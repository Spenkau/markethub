<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreateLocalAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_creates_admin_with_a_hashed_password(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('markethub:admin', ['--name' => 'Local Admin', '--email' => 'ADMIN@example.test'])
            ->expectsQuestion('Пароль (не менее 12 символов)', 'Test-Only-Password-123!')
            ->assertSuccessful();

        $admin = User::where('email', 'admin@example.test')->firstOrFail();
        $this->assertTrue($admin->is_admin);
        $this->assertTrue(Hash::check('Test-Only-Password-123!', $admin->password));
        $this->assertNotSame('Test-Only-Password-123!', $admin->password);
    }

    public function test_empty_password_is_rejected(): void
    {
        $this->app['env'] = 'local';

        $this->artisan('markethub:admin', ['--name' => 'Local Admin', '--email' => 'admin@example.test'])
            ->expectsQuestion('Пароль (не менее 12 символов)', '')
            ->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }

    public function test_command_does_not_promote_existing_users(): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create(['email' => 'existing@example.test']);

        $this->artisan('markethub:admin', ['--name' => 'Local Admin', '--email' => $user->email])
            ->expectsQuestion('Пароль (не менее 12 символов)', 'Test-Only-Password-123!')
            ->assertFailed();

        $this->assertFalse($user->fresh()->is_admin);
        $this->assertDatabaseCount('users', 1);
    }

    public function test_command_refuses_to_run_outside_local_environment(): void
    {
        $this->artisan('markethub:admin')->assertFailed();

        $this->assertDatabaseCount('users', 0);
    }
}
