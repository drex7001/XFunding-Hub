<?php

namespace App\Actions;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PDO;
use RuntimeException;

class CopySqliteDemoToPostgres
{
    private const EXCLUDED = ['migrations', 'sessions', 'password_reset_tokens', 'cache', 'cache_locks'];

    private const ENCRYPTED = [
        'settlement_instructions' => ['details'],
        'deals' => ['instruction_snapshot'],
        'deal_instruction_changes' => ['previous_snapshot'],
        'settlement_confirmations' => ['reference', 'note', 'review_reason'],
        'deal_disputes' => ['description', 'resolution_note'],
    ];

    /** @return array<string, int> */
    public function handle(string $source, bool $dryRun = false): array
    {
        if (! app()->environment(['local', 'testing']) || DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException('Import requires a local/test application targeting PostgreSQL.');
        }
        if (! is_file($source) || ! is_readable($source)) {
            throw new RuntimeException('Choose an existing readable SQLite source file.');
        }
        $sqlite = new PDO('sqlite:'.realpath($source), options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $sqlite->exec('PRAGMA query_only = ON');
        $sqlite->beginTransaction();
        try {
            $tables = $sqlite->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN);
            $tables = array_values(array_diff($tables, self::EXCLUDED));
            foreach (['users', 'banks', 'funding_requests', 'deals', 'fee_payments', 'payment_receipts', 'notifications'] as $required) {
                if (! in_array($required, $tables, true)) {
                    throw new RuntimeException('The source must contain the complete marketplace schema.');
                }
            }
            $tables = $this->orderedTables($sqlite, $tables);

            return DB::transaction(function () use ($sqlite, $tables, $dryRun): array {
                DB::select('select pg_advisory_xact_lock(78140616)');
                $schema = DB::connection()->getSchemaBuilder();
                $columns = [];
                foreach ($tables as $table) {
                    if (! $schema->hasTable($table)) {
                        throw new RuntimeException('Run all marketplace migrations on the target before import.');
                    }
                    $columns[$table] = collect($schema->getColumns($table))->keyBy('name')->all();
                    if (DB::table($table)->exists()) {
                        throw new RuntimeException('The target marketplace tables must be empty. Existing data will not be overwritten.');
                    }
                }
                $counts = [];
                $payments = [];
                foreach ($tables as $table) {
                    $rows = $sqlite->query('SELECT * FROM "'.$table.'"')->fetchAll(PDO::FETCH_ASSOC);
                    $counts[$table] = count($rows);
                    foreach ($rows as &$row) {
                        if (array_diff(array_keys($row), array_keys($columns[$table])) !== []) {
                            throw new RuntimeException('The source and target schemas do not match.');
                        }
                        foreach (self::ENCRYPTED[$table] ?? [] as $field) {
                            if (($row[$field] ?? null) !== null) {
                                try {
                                    Crypt::decryptString($row[$field]);
                                } catch (DecryptException) {
                                    throw new RuntimeException('The application key does not decrypt the source marketplace records.');
                                }
                            }
                        }
                        if (in_array($table, ['funding_documents', 'bank_documents'], true)) {
                            if (! Storage::disk('private')->exists($row['path'])) {
                                throw new RuntimeException('A source document is missing from the shared private storage.');
                            }
                            if (isset($row['sha256']) && ! hash_equals($row['sha256'], hash('sha256', Storage::disk('private')->get($row['path'])))) {
                                throw new RuntimeException('A source document does not match its recorded checksum.');
                            }
                        }
                        foreach ($row as $name => $value) {
                            if ($value !== null && in_array($columns[$table][$name]['type_name'], ['bool', 'boolean'], true)) {
                                $row[$name] = (bool) $value;
                            }
                        }
                        if ($table === 'deals' && ($row['verified_payment_id'] ?? null) !== null) {
                            $payments[$row['id']] = $row['verified_payment_id'];
                            $row['verified_payment_id'] = null;
                        }
                    }
                    unset($row);
                    if (! $dryRun) {
                        foreach (array_chunk($rows, 100) as $batch) {
                            DB::table($table)->insert($batch);
                        }
                    }
                }
                if (! $dryRun) {
                    foreach ($payments as $dealId => $paymentId) {
                        DB::table('deals')->where('id', $dealId)->update(['verified_payment_id' => $paymentId]);
                    }
                    foreach ($counts as $table => $count) {
                        if (DB::table($table)->count() !== $count) {
                            throw new RuntimeException('Imported row counts do not match the source snapshot.');
                        }
                        foreach ($columns[$table] as $column) {
                            if ($column['auto_increment']) {
                                $wrapped = DB::connection()->getQueryGrammar()->wrapTable($table);
                                $name = DB::connection()->getQueryGrammar()->wrap($column['name']);
                                DB::select('select setval(pg_get_serial_sequence(?, ?), coalesce((select max('.$name.') from '.$wrapped.'), 0) + 1, false)', [$table, $column['name']]);
                            }
                        }
                    }
                }

                return $counts;
            });
        } finally {
            $sqlite->rollBack();
        }
    }

    /** @param array<int, string> $tables
     * @return array<int, string>
     */
    private function orderedTables(PDO $sqlite, array $tables): array
    {
        $dependencies = [];
        foreach ($tables as $table) {
            if (! preg_match('/^[a-z_][a-z0-9_]*$/D', $table)) {
                throw new RuntimeException('The source contains an unsupported table name.');
            }
            $dependencies[$table] = [];
            foreach ($sqlite->query('PRAGMA foreign_key_list("'.$table.'")')->fetchAll(PDO::FETCH_ASSOC) as $foreign) {
                if ($table === 'deals' && $foreign['from'] === 'verified_payment_id') {
                    continue;
                }
                if (in_array($foreign['table'], $tables, true) && $foreign['table'] !== $table) {
                    $dependencies[$table][] = $foreign['table'];
                }
            }
        }
        $ordered = [];
        while ($dependencies !== []) {
            $ready = array_keys(array_filter($dependencies, fn (array $requires): bool => array_diff($requires, $ordered) === []));
            if ($ready === []) {
                throw new RuntimeException('The source has an unsupported circular table dependency.');
            }
            foreach ($ready as $table) {
                $ordered[] = $table;
                unset($dependencies[$table]);
            }
        }

        return $ordered;
    }
}
