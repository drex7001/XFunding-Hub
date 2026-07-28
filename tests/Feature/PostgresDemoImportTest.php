<?php

use App\Actions\CopySqliteDemoToPostgres;
use App\BankRole;
use App\Models\Deal;
use App\Models\User;
use Illuminate\Encryption\Encrypter;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

function sqliteDemoSource(): array
{
    $path = storage_path('framework/testing/import-'.Str::uuid().'.sqlite');
    touch($path);
    chmod($path, 0600);
    $default = DB::getDefaultConnection();
    $settings = config('database.connections.sqlite');
    config(['database.connections.sqlite.database' => $path, 'database.connections.sqlite.url' => null]);
    DB::purge('sqlite');
    Artisan::call('migrate', ['--database' => 'sqlite', '--force' => true]);
    DB::setDefaultConnection('sqlite');
    try {
        $deal = unlockedDeal();
        $deal->borrowerBank->update(['name' => 'Preserved user-edited borrower']);
        confirmedInstitution($deal, $deal->borrowerBank);
        confirmedInstitution($deal->fresh(), $deal->lenderBank);
        $document = financialEvidence($deal->fundingRequest);
        $payload = DB::table('deals')->where('id', $deal->id)->value('instruction_snapshot');
        $data = ['path' => $path, 'dealId' => $deal->id, 'bankId' => $deal->borrower_bank_id,
            'documentPath' => $document->path, 'snapshot' => $payload, 'users' => User::query()->count()];
    } finally {
        DB::setDefaultConnection($default);
        DB::purge('sqlite');
        config(['database.connections.sqlite' => $settings]);
    }
    $data['hash'] = hash_file('sha256', $path);
    test()->beforeApplicationDestroyed(fn () => is_file($path) ? unlink($path) : null);

    return $data;
}

function requirePostgresImportTarget(): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the isolated PostgreSQL target.');
    }
}

it('preserves encrypted paid history, files, identities and PostgreSQL sequences when importing SQLite demo data', function () {
    requirePostgresImportTarget();
    $this->freezeTime();
    enableDemoPayments();
    Storage::fake('private');
    $source = sqliteDemoSource();
    $counts = app(CopySqliteDemoToPostgres::class)->handle($source['path']);
    $deal = Deal::query()->findOrFail($source['dealId']);

    expect($counts['deals'])->toBe(1);
    expect($deal->borrowerBank->name)->toBe('Preserved user-edited borrower');
    expect($deal->status->value)->toBe('settled');
    expect($deal->hasVerifiedFee())->toBeTrue();
    expect($deal->hasBothConfirmations())->toBeTrue();
    expect(DB::table('deals')->where('id', $deal->id)->value('instruction_snapshot'))->toBe($source['snapshot']);
    expect(hash_file('sha256', $source['path']))->toBe($source['hash']);
    expect(User::factory()->create()->id)->toBeGreaterThan($source['users']);
    expect(DB::table('sessions')->count())->toBe(0);
    signInUser(bankStaff(BankRole::Approver, $deal->borrowerBank));
    $this->getJson(route('api.deals.settlement', $deal))->assertOk()->assertJsonPath('data.instructions.accountNumber', 'DEMO000012345678');
});

it('validates import as a dry run without writing target records or the source', function () {
    requirePostgresImportTarget();
    $this->freezeTime();
    enableDemoPayments();
    Storage::fake('private');
    $source = sqliteDemoSource();

    $this->artisan('marketplace:import-sqlite', ['source' => $source['path'], '--dry-run' => true])
        ->expectsOutput('Source validated. No target rows were written.')->assertSuccessful();
    expect(User::query()->count())->toBe(0);
    expect(hash_file('sha256', $source['path']))->toBe($source['hash']);
});

it('refuses import into a target with existing users without overwriting either database', function () {
    requirePostgresImportTarget();
    $this->freezeTime();
    enableDemoPayments();
    Storage::fake('private');
    $source = sqliteDemoSource();
    $existing = User::factory()->create();
    expect(fn () => app(CopySqliteDemoToPostgres::class)->handle($source['path']))->toThrow(RuntimeException::class, 'must be empty');
    expect(User::query()->sole()->id)->toBe($existing->id);
    expect(hash_file('sha256', $source['path']))->toBe($source['hash']);
});

it('rolls back import when the application key cannot decrypt source records', function () {
    requirePostgresImportTarget();
    $this->freezeTime();
    enableDemoPayments();
    Storage::fake('private');
    $source = sqliteDemoSource();
    app()->instance('encrypter', new Encrypter(str_repeat('x', 32), 'AES-256-CBC'));
    Crypt::clearResolvedInstance('encrypter');

    expect(fn () => app(CopySqliteDemoToPostgres::class)->handle($source['path']))->toThrow(RuntimeException::class, 'does not decrypt');
    expect(User::query()->count())->toBe(0);
});

it('rolls back import when private source evidence is missing or changed', function (string $failure) {
    requirePostgresImportTarget();
    $this->freezeTime();
    enableDemoPayments();
    Storage::fake('private');
    $source = sqliteDemoSource();
    if ($failure === 'missing') {
        Storage::disk('private')->delete($source['documentPath']);
    } else {
        Storage::disk('private')->put($source['documentPath'], 'changed source evidence');
    }
    expect(fn () => app(CopySqliteDemoToPostgres::class)->handle($source['path']))->toThrow(RuntimeException::class);
    expect(User::query()->count())->toBe(0);
})->with(['missing', 'changed']);

it('refuses the import command in production', function () {
    $this->app->detectEnvironment(fn (): string => 'production');
    $this->artisan('marketplace:import-sqlite', ['source' => '/missing.sqlite'])->assertFailed();
});

it('refuses import when the destination is not PostgreSQL', function () {
    if (DB::getDefaultConnection() !== 'sqlite') {
        config(['database.default' => 'sqlite', 'database.connections.sqlite.database' => ':memory:']);
        DB::purge('sqlite');
    }
    expect(fn () => app(CopySqliteDemoToPostgres::class)->handle('/missing.sqlite'))->toThrow(RuntimeException::class, 'targeting PostgreSQL');
});
