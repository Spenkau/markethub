<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class PublicDemo
{
    public const ORGANIZATION = 'markethub-public-demo';

    public function assertDedicatedDatabase(): void
    {
        $connection = DB::connection();
        $database = $connection->getDatabaseName();
        if (! config('demo.enabled') || $connection->getDriverName() !== 'pgsql'
            || config('demo.database') !== $database
            || (! str_ends_with($database, '_demo') && ! (app()->environment('testing') && str_ends_with($database, '_test')))) {
            throw new RuntimeException('Demo requires explicit enablement and a dedicated, explicitly named PostgreSQL _demo database.');
        }
    }

    public function assertEmpty(): void
    {
        // Refuse any existing application data, including orphaned credentials/jobs.
        foreach (Schema::getTableListing(schemaQualified: false) as $table) {
            if (! in_array($table, ['migrations', 'cache', 'cache_locks', 'system_checks'], true) && DB::table($table)->exists()) {
                throw new RuntimeException('Bootstrap refused: database is not empty ('.$table.'). Use a new dedicated demo database.');
            }
        }
    }

    public function user(): ?User
    {
        try {
            $this->assertDedicatedDatabase();
            if (! Schema::hasColumn('users', 'is_demo_access') || DB::table('users')->count() !== 1
                || DB::table('organizations')->count() !== 1
                || DB::table('marketplace_credentials')->exists()
                || DB::table('marketplace_accounts')->where(function ($query) {
                    $query->where('is_demo', false)->orWhereNotIn('marketplace', ['wildberries', 'ozon', 'shopify_demo'])
                        ->orWhereNotNull('shopify_client_id')->orWhereNotNull('shopify_client_secret')->orWhereNotNull('shop_domain')
                        ->orWhereRaw("configuration IS NOT NULL AND configuration <> '{}'::jsonb");
                })->exists()) {
                return null;
            }
            $user = User::where('is_demo_access', true)->where('is_admin', false)->first();

            return $user && $user->organizations()->where('slug', self::ORGANIZATION)->exists() ? $user : null;
        } catch (\Throwable $exception) {
            // An unconfigured deployment must never expose an alternative auth path.
            return null;
        }
    }
}
