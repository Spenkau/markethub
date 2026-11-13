import { spawnSync } from 'node:child_process';
import { randomBytes } from 'node:crypto';
import { fileURLToPath } from 'node:url';
const root = fileURLToPath(new URL('../../../', import.meta.url));
export const fixturePassword = randomBytes(32).toString('base64url');
export function database(setup: boolean, writable = false) {
    const result = spawnSync(process.env.PHP_BINARY || 'php', [], {
        cwd: root,
        encoding: 'utf8',
        input: String.raw`<?php
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (!app()->environment('testing') || config('database.default') !== 'pgsql' || !str_ends_with(Illuminate\Support\Facades\DB::connection()->getDatabaseName(), '_test')) throw new RuntimeException('Isolated test database required');
Illuminate\Support\Facades\Artisan::call('migrate:fresh',['--force'=>true]);
if (getenv('DEMO_SETUP') !== '1') exit;
config(['demo.enabled'=>true,'demo.database'=>Illuminate\Support\Facades\DB::connection()->getDatabaseName()]);
$result=Illuminate\Support\Facades\Artisan::call('markethub:demo-bootstrap',['--confirm-environment'=>'testing','--confirm-database'=>Illuminate\Support\Facades\DB::connection()->getDatabaseName(),'--no-interaction'=>true]);
if ($result!==0) throw new RuntimeException(Illuminate\Support\Facades\Artisan::output());
App\Models\User::first()->forceFill(['tour_auto_start'=>false])->save();
if (getenv('VISUAL_WRITABLE') === '1') App\Models\User::first()->forceFill(['is_demo_access'=>false])->save();
echo 'DEMO_READY';`,
        env: {
            ...process.env,
            APP_ENV: 'testing',
            DEMO_SETUP: setup ? '1' : '0',
            DEMO_BOOTSTRAP_PASSWORD: fixturePassword,
            VISUAL_WRITABLE: writable ? '1' : '0',
        },
    });
    if (result.status !== 0 || (setup && !result.stdout.includes('DEMO_READY')))
        throw new Error(result.stderr || result.stdout);
}
