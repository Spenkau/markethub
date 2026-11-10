<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AdminPanelTest extends TestCase
{
    use RefreshDatabase;

    public static function panelPages(): array
    {
        return [
            'dashboard' => ['/admin', 'Дашборд'],
            'orders' => ['/admin/orders', 'Заказов пока нет'],
            'products' => ['/admin/products', 'Каталог пока пуст'],
            'inventory' => ['/admin/inventory', 'Нет данных об остатках'],
            'marketplaces' => ['/admin/marketplaces', 'Маркетплейсы не подключены'],
            'analytics' => ['/admin/analytics', 'Недостаточно данных для аналитики'],
            'settings' => ['/admin/settings', 'Настройки будут доступны позже'],
        ];
    }

    #[DataProvider('panelPages')]
    public function test_guest_is_redirected_to_login(string $path, string $text): void
    {
        $this->get($path)->assertRedirect('/admin/login');
    }

    #[DataProvider('panelPages')]
    public function test_administrator_can_open_every_section(string $path, string $text): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get($path)->assertOk()->assertSee($text)->assertSee('MarketHub');
    }

    #[DataProvider('panelPages')]
    public function test_regular_user_is_forbidden_even_in_local_environment(string $path, string $text): void
    {
        $this->app['env'] = 'local';
        $user = User::factory()->create();

        $this->assertFalse($user->is_admin);
        $this->actingAs($user)->get($path)->assertForbidden();
    }

    public function test_dashboard_handles_an_empty_business_database(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);

        $this->actingAs($admin)->get('/admin')->assertOk()
            ->assertSee('Общая выручка')->assertSee('0 ₽')
            ->assertSee('Количество заказов')->assertSee('Количество товаров')
            ->assertSee('Активные маркетплейсы')->assertSee('Пока нет данных о продажах')
            ->assertSee('Заказов пока нет')->assertSee('Нет активных интеграций');
    }

    public function test_public_registration_is_unavailable(): void
    {
        $this->get('/register')->assertNotFound();
        $this->get('/admin/register')->assertNotFound();
        $this->post('/register')->assertNotFound();
        $this->post('/admin/register')->assertNotFound();
        $this->get('/admin/login')->assertOk()->assertDontSee('Зарегистрироваться');
    }

    public function test_admin_can_log_in_using_filament_authentication(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'password'])
            ->call('authenticate')->assertHasNoFormErrors()->assertRedirect('/admin');

        $this->assertAuthenticatedAs($admin);
    }

    public function test_user_without_panel_access_cannot_log_in(): void
    {
        $user = User::factory()->create();
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate')->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_incorrect_password_does_not_authenticate(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        Livewire::test(Login::class)
            ->fillForm(['email' => $admin->email, 'password' => 'incorrect-password'])
            ->call('authenticate')->assertHasFormErrors(['email']);

        $this->assertGuest();
    }

    public function test_admin_flag_cannot_be_mass_assigned(): void
    {
        $user = new User(['name' => 'User', 'email' => 'user@example.test', 'is_admin' => true]);

        $this->assertFalse($user->is_admin);
    }
}
