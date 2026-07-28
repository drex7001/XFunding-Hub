<?php

use App\Actions\DemoPaymentProvider;
use App\Actions\ManageSettlementConfirmations;
use App\Actions\ProcessFeePaymentNotification;
use App\Actions\SelectOffer;
use App\BankRole;
use App\DealStatus;
use App\Models\Deal;
use App\Models\FundingRequest;
use App\Models\PaymentNotification;
use App\Models\PaymentReceipt;
use App\Models\SelectionProposal;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Concurrency;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

function committedPostgresScenario(Closure $scenario): void
{
    if (DB::connection()->getDriverName() !== 'pgsql') {
        test()->markTestSkipped('Requires the isolated PostgreSQL configuration.');
    }
    if (DB::connection()->getDatabaseName() !== 'xfunding_hub_testing' || ! app()->environment('testing')) {
        throw new LogicException('Concurrent tests may only rebuild xfunding_hub_testing.');
    }
    DB::commit();
    try {
        $scenario();
    } finally {
        Artisan::call('migrate:fresh', ['--force' => true]);
        DB::beginTransaction();
    }
}

/** @param array<int, array{operation: string, id: string, actor?: int, payload?: array<string, mixed>}> $jobs */
function raceMarketplaceActions(array $jobs): array
{
    $database = config('database.connections.pgsql');
    $payments = config('payments');
    $barrier = storage_path('framework/testing/concurrency-'.Str::uuid());
    File::ensureDirectoryExists($barrier);
    $tasks = [];
    foreach ($jobs as $index => $job) {
        $tasks[] = static function () use ($database, $payments, $barrier, $index, $job): array {
            if (! app()->environment('testing') || $database['database'] !== 'xfunding_hub_testing') {
                throw new LogicException('The concurrency worker requires the isolated test database.');
            }
            config(['database.default' => 'pgsql', 'database.connections.pgsql' => $database, 'payments' => $payments]);
            DB::purge('pgsql');
            $backend = (int) DB::selectOne('select pg_backend_pid() as id')->id;
            file_put_contents($barrier.'/'.$index.'.ready', 'ready');
            $until = microtime(true) + 10;
            while (count(glob($barrier.'/*.ready')) !== 2) {
                if (microtime(true) > $until) {
                    throw new RuntimeException('The second database session did not reach the barrier.');
                }
                usleep(10000);
            }
            try {
                if ($job['operation'] === 'payment') {
                    $provider = app(DemoPaymentProvider::class);
                    $body = json_encode($job['payload'], JSON_THROW_ON_ERROR);
                    $timestamp = (string) now()->getTimestamp();
                    $request = Request::create('/api/payments/demo/notifications', 'POST', [], [], [], [
                        'CONTENT_TYPE' => 'application/json', 'HTTP_X_PAYMENT_TIMESTAMP' => $timestamp,
                        'HTTP_X_PAYMENT_SIGNATURE' => $provider->signature($body, $timestamp),
                    ], $body);
                    $result = app(ProcessFeePaymentNotification::class)->handle($request);
                } else {
                    $actor = User::query()->findOrFail($job['actor']);
                    $result = match ($job['operation']) {
                        'selection-proposal' => app(SelectOffer::class)->propose($actor, FundingRequest::query()->findOrFail($job['id']), $job['payload']['token'])->id,
                        'selection' => app(SelectOffer::class)->review($actor, SelectionProposal::query()->findOrFail($job['id']), true, null)->id,
                        default => app(ManageSettlementConfirmations::class)->review($actor, SettlementConfirmation::query()->findOrFail($job['id']), true, null)->status->value,
                    };
                }

                return ['status' => 200, 'result' => $result, 'backend' => $backend];
            } catch (HttpExceptionInterface $exception) {
                return ['status' => $exception->getStatusCode(), 'result' => 'refused', 'backend' => $backend];
            }
        };
    }
    try {
        $results = Concurrency::driver('process')->run($tasks, timeout: 20);
        expect($results[0]['backend'])->not->toBe($results[1]['backend']);

        return $results;
    } finally {
        File::deleteDirectory($barrier);
    }
}

it('reserves one winner when independent sessions race the same selection approval', function () {
    committedPostgresScenario(function (): void {
        enableDemoPayments();
        $maker = bankStaff();
        $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
        $offer = marketOffer(bankStaff(), $request);
        $proposal = app(SelectOffer::class)->propose($maker, $request, $offer->borrower_token);
        $first = bankStaff(BankRole::Approver, $maker->membership->bank);
        $second = bankStaff(BankRole::Approver, $maker->membership->bank);
        $results = raceMarketplaceActions([
            ['operation' => 'selection', 'id' => $proposal->id, 'actor' => $first->id],
            ['operation' => 'selection', 'id' => $proposal->id, 'actor' => $second->id],
        ]);
        expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 409]);
        expect(Deal::query()->where('funding_request_id', $request->id)->count())->toBe(1);
        expect($proposal->fresh()->status)->toBe('approved');
    });
})->group('postgres-concurrency');

it('allocates one canonical receipt when PostgreSQL payment sessions race', function (string $delivery) {
    committedPostgresScenario(function () use ($delivery): void {
        enableDemoPayments();
        $payment = feePaymentAttempt();
        $first = paymentNotificationPayload($payment);
        $second = $first;
        if ($delivery !== 'same event') {
            $second['event_reference'] = (string) Str::uuid();
        }
        if ($delivery === 'new charge') {
            $second['charge_reference'] = (string) Str::uuid();
        }
        $results = raceMarketplaceActions([
            ['operation' => 'payment', 'id' => $payment->id, 'payload' => $first],
            ['operation' => 'payment', 'id' => $payment->id, 'payload' => $second],
        ]);
        $expected = $delivery === 'new charge' ? ['refund_review', 'verified'] : ['duplicate', 'verified'];
        expect(collect($results)->pluck('result')->sort()->values()->all())->toBe($expected);
        expect($payment->deal->fresh()->status)->toBe(DealStatus::Unlocked);
        expect(PaymentReceipt::query()->where('status', 'verified')->count())->toBe(1);
        expect(PaymentReceipt::query()->count())->toBe($delivery === 'new charge' ? 2 : 1);
        expect(PaymentNotification::query()->count())->toBe($delivery === 'same event' ? 1 : 2);
    });
})->with(['same event', 'same charge', 'new charge'])->group('postgres-concurrency');

it('settles once when both bank approval sessions overlap', function () {
    committedPostgresScenario(function (): void {
        enableDemoPayments();
        $deal = unlockedDeal();
        $action = app(ManageSettlementConfirmations::class);
        $borrower = $action->propose(bankStaff(BankRole::Maker, $deal->borrowerBank), $deal, settlementPayload());
        $lender = $action->propose(bankStaff(BankRole::Maker, $deal->lenderBank), $deal, settlementPayload());
        $borrowerApprover = bankStaff(BankRole::Approver, $deal->borrowerBank);
        $lenderApprover = bankStaff(BankRole::Approver, $deal->lenderBank);
        $results = raceMarketplaceActions([
            ['operation' => 'confirmation', 'id' => $borrower->id, 'actor' => $borrowerApprover->id],
            ['operation' => 'confirmation', 'id' => $lender->id, 'actor' => $lenderApprover->id],
        ]);
        expect(collect($results)->pluck('result')->sort()->values()->all())->toBe(['settled', 'unlocked']);
        expect($deal->fresh()->status)->toBe(DealStatus::Settled);
        expect($deal->events()->where('kind', 'settled')->count())->toBe(1);
    });
})->group('postgres-concurrency');

it('keeps one pending choice when two makers simultaneously propose different lender offers', function () {
    committedPostgresScenario(function (): void {
        enableDemoPayments();
        $maker = bankStaff();
        $otherMaker = bankStaff(BankRole::Maker, $maker->membership->bank);
        $request = FundingRequest::factory()->published()->create(['bank_id' => $maker->membership->bank_id]);
        $offers = [marketOffer(bankStaff(), $request), marketOffer(bankStaff(), $request)];
        $results = raceMarketplaceActions([
            ['operation' => 'selection-proposal', 'id' => $request->id, 'actor' => $maker->id, 'payload' => ['token' => $offers[0]->borrower_token]],
            ['operation' => 'selection-proposal', 'id' => $request->id, 'actor' => $otherMaker->id, 'payload' => ['token' => $offers[1]->borrower_token]],
        ]);
        expect(collect($results)->pluck('status')->sort()->values()->all())->toBe([200, 409]);
        expect(SelectionProposal::query()->where('funding_request_id', $request->id)->count())->toBe(1);
        $proposal = SelectionProposal::query()->where('funding_request_id', $request->id)->sole();
        $deal = app(SelectOffer::class)->review(bankStaff(BankRole::Approver, $maker->membership->bank), $proposal, true, null);
        expect($deal->offer_id)->toBe($proposal->offer_id);
        expect(Deal::query()->where('funding_request_id', $request->id)->count())->toBe(1);
    });
})->group('postgres-concurrency');
