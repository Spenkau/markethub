<?php

namespace App\Console\Commands;

use App\Services\PublicDemo;
use App\Services\SyntheticDemoFixtures;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class DemoBootstrap extends Command
{
    protected $signature = 'markethub:demo-bootstrap {--confirm-environment=} {--confirm-database=} {--email=demo@example.invalid}';

    protected $description = 'Prepare synthetic read-only portfolio data in an empty dedicated demo database';

    public function handle(PublicDemo $demo, SyntheticDemoFixtures $fixtures): int
    {
        $writing = false;
        try {
            $demo->assertDedicatedDatabase();
            if ($this->option('confirm-environment') !== app()->environment() || $this->option('confirm-database') !== DB::connection()->getDatabaseName()) {
                throw new \RuntimeException('Pass --confirm-environment and --confirm-database matching the exact target.');
            }
            $demo->assertEmpty();
            $email = $this->option('email');
            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new \RuntimeException('A valid demo email is required.');
            }
            $password = getenv('DEMO_BOOTSTRAP_PASSWORD') ?: ($this->input->isInteractive() ? $this->secret('Demo password (at least 16 characters)', false) : null);
            if (! is_string($password) || strlen($password) < 16) {
                throw new \RuntimeException('Provide a password of at least 16 characters via hidden prompt or DEMO_BOOTSTRAP_PASSWORD.');
            }
            $writing = true;
            DB::transaction(function () use ($demo, $fixtures, $email, $password) {
                DB::select("SELECT pg_advisory_xact_lock(hashtextextended('markethub:demo-bootstrap', 0))");
                $demo->assertEmpty();
                $fixtures->create($email, $password);
            });
            $this->info('Synthetic read-only demo prepared. No credentials or external requests were created.');

            return self::SUCCESS;
        } catch (\Throwable $exception) {
            // Do not dump exception context or SQL bindings (may include a password).
            $this->error(! $writing && $exception instanceof \RuntimeException && ! $exception instanceof QueryException
                ? $exception->getMessage() : 'Demo bootstrap failed; the transaction was rolled back. Check schema and application configuration.');

            return self::FAILURE;
        }
    }
}
