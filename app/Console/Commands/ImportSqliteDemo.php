<?php

namespace App\Console\Commands;

use App\Actions\CopySqliteDemoToPostgres;
use Illuminate\Console\Command;
use Throwable;

class ImportSqliteDemo extends Command
{
    protected $signature = 'marketplace:import-sqlite {source : Existing SQLite marketplace database} {--dry-run : Validate without inserting target rows}';

    protected $description = 'Copy local demo history into an empty PostgreSQL database without changing the SQLite source';

    public function handle(CopySqliteDemoToPostgres $action): int
    {
        try {
            $counts = $action->handle($this->argument('source'), (bool) $this->option('dry-run'));
        } catch (Throwable $exception) {
            $this->error($exception instanceof \RuntimeException && ! $exception instanceof \PDOException ? $exception->getMessage() : 'Import failed. No target changes were committed.');

            return self::FAILURE;
        }
        $this->table(['Table', 'Rows'], collect($counts)->map(fn (int $count, string $table): array => [$table, $count])->values()->all());
        $this->info($this->option('dry-run') ? 'Source validated. No target rows were written.' : 'Demo history copied. The SQLite source is unchanged.');
        $this->line('Sessions, password-reset tokens and cache are excluded. Sign in again. Private files and the existing application key must be retained.');

        return self::SUCCESS;
    }
}
