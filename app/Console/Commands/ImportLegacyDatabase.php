<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use PDO;

class ImportLegacyDatabase extends Command
{
    protected $signature = 'markethub:import-sqlite {path : Путь к резервной копии прежней базы}';

    protected $description = 'Неразрушающий перенос прежних данных в пустую локальную PostgreSQL-базу';

    public function handle(): int
    {
        if (! app()->environment('local') || DB::getDriverName() !== 'pgsql') {
            $this->error('Требуется local и PostgreSQL.');

            return self::FAILURE;
        }
        foreach (['users', 'organizations', 'products', 'marketplace_accounts', 'marketplace_listings'] as $table) {
            if (DB::table($table)->exists()) {
                $this->error('Перенос разрешён только в пустую базу после миграций.');

                return self::FAILURE;
            }
        }
        $path = realpath($this->argument('path'));
        if (! $path || ! is_file($path)) {
            $this->error('Исходный файл не найден.');

            return self::FAILURE;
        }
        $source = new PDO('sqlite:'.$path, null, null, [PDO::SQLITE_ATTR_OPEN_FLAGS => PDO::SQLITE_OPEN_READONLY]);
        $report = [];
        DB::transaction(function () use ($source, &$report): void {
            $activeOrganizations = [];
            foreach (['organizations', 'users', 'organization_user', 'products', 'marketplace_accounts', 'marketplace_listings', 'system_checks'] as $table) {
                $rows = $source->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);
                $inserted = 0;
                foreach ($rows as $row) {
                    if ($table === 'users') {
                        $activeOrganizations[$row['id']] = $row['active_organization_id'];
                        $row['active_organization_id'] = null;
                    }
                    if ($table === 'organization_user' && (! DB::table('users')->where('id', $row['user_id'])->exists() || ! DB::table('organizations')->where('id', $row['organization_id'])->exists())) {
                        $this->warn('Пропущена исходная осиротевшая связь organization_user: user_id='.$row['user_id'].', organization_id='.$row['organization_id']);

                        continue;
                    }
                    foreach (['is_admin', 'is_active', 'is_demo'] as $boolean) {
                        if (array_key_exists($boolean, $row)) {
                            $row[$boolean] = (bool) $row[$boolean];
                        }
                    }
                    DB::table($table)->insert($row);
                    $inserted++;
                }
                $report[$table] = ['source' => count($rows), 'imported' => $inserted];
                if (! in_array($table, ['organization_user', 'system_checks'])) {
                    DB::statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), COALESCE((SELECT MAX(id) FROM {$table}), 1), EXISTS(SELECT 1 FROM {$table}))");
                }
            }
            foreach ($activeOrganizations as $userId => $organizationId) {
                if ($organizationId && DB::table('organization_user')->where('user_id', $userId)->where('organization_id', $organizationId)->exists()) {
                    DB::table('users')->where('id', $userId)->update(['active_organization_id' => $organizationId]);
                }
            }
        });
        $this->line(json_encode($report, JSON_PRETTY_PRINT));
        $this->info('Перенос завершён. Исходная база открывалась только для чтения.');

        return self::SUCCESS;
    }
}
