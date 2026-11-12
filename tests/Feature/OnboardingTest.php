<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\User;
use App\Models\UserTourProgress;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class OnboardingTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user);
    }

    private function progress(string $action, array $extra = [], string $tour = 'welcome')
    {
        return $this->patchJson('/api/v1/onboarding/tours/'.$tour, ['action' => $action, 'version' => 1, 'step_id' => 'intro', 'step_index' => 0, ...$extra]);
    }

    private function organization(): Organization
    {
        $org = Organization::factory()->create();
        $this->user->organizations()->attach($org);
        $this->user->active_organization_id = $org->id;
        $this->user->save();

        return $org;
    }

    public function test_authenticated_user_can_create_an_organization_and_retry_safely(): void
    {
        $body = ['name' => 'Моя команда', 'request_id' => (string) Str::uuid()];
        $id = $this->postJson('/api/v1/organizations', $body)->assertCreated()->json('data.id');
        $this->postJson('/api/v1/organizations', $body)->assertOk()->assertJsonPath('data.id', $id);
        $this->postJson('/api/v1/organizations', [...$body, 'name' => 'Другие данные'])->assertConflict();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.active_organization_id', $id);
        $this->assertDatabaseHas('organization_user', ['user_id' => $this->user->id, 'organization_id' => $id]);
        $this->postJson('/api/v1/organizations', ['name' => ' ', 'request_id' => (string) Str::uuid()])->assertUnprocessable();
    }

    public function test_business_checklist_is_not_completed_by_tours_and_is_tenant_scoped(): void
    {
        $this->getJson('/api/v1/onboarding/checklist')->assertOk()->assertJsonPath('data.completed', 0);
        $org = $this->organization();
        $this->progress('start')->assertOk();
        $this->progress('complete')->assertOk();
        $this->getJson('/api/v1/onboarding/checklist')->assertJsonPath('data.completed', 1);
        $warehouse = new Warehouse(['name' => 'Основной', 'code' => 'MAIN', 'type' => 'own', 'is_active' => true]);
        $warehouse->organization_id = $org->id;
        $warehouse->save();
        $product = Product::factory()->create(['organization_id' => $org->id]);
        $supplier = Supplier::create(['name' => 'Поставщик']);
        $this->getJson('/api/v1/onboarding/checklist')->assertJsonPath('data.completed', 4);
        $order = $this->postJson('/api/v1/purchase-orders', ['request_id' => (string) Str::uuid(), 'supplier_id' => $supplier->id, 'warehouse_id' => $warehouse->id, 'currency' => 'RUB', 'items' => [['product_id' => $product->id, 'quantity' => 2, 'unit_price' => '1.00']]])->assertCreated()->json('data');
        $this->postJson('/api/v1/purchase-orders/'.$order['id'].'/order')->assertOk();
        $receipt = $this->postJson('/api/v1/goods-receipts', ['request_id' => (string) Str::uuid(), 'purchase_order_id' => $order['id'], 'items' => [['purchase_order_item_id' => $order['items'][0]['id'], 'quantity' => 1]]])->assertCreated()->json('data');
        $this->getJson('/api/v1/onboarding/checklist')->assertJsonPath('data.completed', 5);
        $this->postJson('/api/v1/goods-receipts/'.$receipt['id'].'/confirm')->assertOk();
        $this->getJson('/api/v1/onboarding/checklist')->assertJsonPath('data.completed', 6);
        $this->getJson('/api/v1/stock-movements')->assertJsonPath('data.0.document.id', $receipt['id'])->assertJsonPath('data.0.document.purchase_order_id', $order['id']);
        $other = Organization::factory()->create();
        $this->user->organizations()->attach($other);
        $this->patchJson('/api/v1/organizations/active', ['organization_id' => $other->id])->assertOk();
        $this->getJson('/api/v1/onboarding/checklist?organization_id='.$org->id)->assertJsonPath('data.organization_id', $other->id)->assertJsonPath('data.completed', 1);
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.progress.0.status', 'completed');
    }

    public function test_progress_resume_completion_and_manual_replay_are_persisted(): void
    {
        $this->progress('start')->assertJsonPath('data.status', 'in_progress');
        $this->progress('advance', ['step_id' => 'second', 'step_index' => 1])->assertJsonPath('data.step_id', 'second');
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.progress.0.step_index', 1);
        $completed = $this->progress('complete', ['step_id' => 'second', 'step_index' => 1])->assertJsonPath('data.status', 'completed')->json('data.completed_at');
        $this->progress('complete')->assertJsonPath('data.completed_at', $completed);
        $this->progress('advance')->assertConflict();
        $this->progress('start')->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.step_index', 0)->assertJsonPath('data.completed_at', null);
    }

    public function test_snooze_offer_cooldowns_and_global_disable_are_enforced_by_server(): void
    {
        $this->progress('offer')->assertOk()->assertJsonPath('data.status', 'not_started');
        $this->progress('offer')->assertConflict();
        $this->progress('snooze', ['snooze_hours' => 24])->assertJsonPath('data.status', 'snoozed');
        $this->travel(23)->hours();
        $this->progress('offer')->assertConflict();
        $this->travel(2)->hours();
        $this->progress('offer')->assertOk();
        $this->patchJson('/api/v1/onboarding/preferences', ['auto_start' => false, 'checklist_collapsed' => true])->assertOk()->assertJsonPath('data.auto_start', false);
        $this->progress('offer', [], 'new-version')->assertConflict();
        $this->progress('start', ['automatic' => true], 'new-version')->assertConflict();
        $this->progress('start', [], 'new-version')->assertOk();
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.preferences.auto_start', false)->assertJsonPath('data.preferences.checklist_collapsed', true);
    }

    public function test_new_versions_are_separate_and_do_not_override_auto_preferences(): void
    {
        $this->progress('start')->assertOk();
        $this->progress('complete')->assertOk();
        $this->travel(16)->minutes();
        $this->progress('offer', ['version' => 2])->assertOk()->assertJsonPath('data.version', 2);
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.progress.0.status', 'completed')->assertJsonPath('data.progress.1.status', 'not_started');
        $this->patchJson('/api/v1/onboarding/preferences', ['auto_start' => false])->assertOk();
        $this->progress('offer', ['version' => 3])->assertConflict();
        $this->progress('start', ['version' => 3])->assertOk();
    }

    public function test_progress_belongs_to_user_not_organization_and_cannot_be_written_for_another_user(): void
    {
        $org = $this->organization();
        $this->progress('start')->assertOk();
        $other = User::factory()->create();
        $other->organizations()->attach($org);
        $this->actingAs($other);
        $this->getJson('/api/v1/onboarding?user_id='.$this->user->id)->assertJsonCount(0, 'data.progress');
        $this->progress('complete', ['user_id' => $this->user->id])->assertUnprocessable();
        $this->patchJson('/api/v1/onboarding/preferences', ['auto_start' => false, 'user_id' => $this->user->id])->assertUnprocessable();
        $this->progress('start')->assertOk();
        $this->assertDatabaseCount('user_tour_progress', 2);
        $this->assertSame('in_progress', UserTourProgress::where('user_id', $this->user->id)->first()->status);
    }

    public function test_dates_remain_correct_across_database_timezones_and_validation_is_bounded(): void
    {
        DB::statement("SET LOCAL TIME ZONE 'Europe/Minsk'");
        $this->travelTo(Carbon::parse('2026-11-11T23:30:00+00:00'));
        $this->progress('start')->assertJsonPath('data.last_started_at', '2026-11-11T23:30:00.000000Z');
        $this->getJson('/api/v1/onboarding')->assertJsonPath('data.progress.0.last_started_at', '2026-11-11T23:30:00.000000Z');
        foreach ([['version' => 0], ['step_index' => 200], ['status' => 'completed'], ['action' => 'wrong'], ['completed_at' => now()->toISOString()], ['snooze_hours' => 721]] as $values) {
            $this->progress('advance', $values)->assertUnprocessable();
        }
    }

    public function test_all_onboarding_endpoints_require_authentication(): void
    {
        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/onboarding')->assertUnauthorized();
        $this->getJson('/api/v1/onboarding/checklist')->assertUnauthorized();
        $this->patchJson('/api/v1/onboarding/preferences', ['auto_start' => false])->assertUnauthorized();
        $this->progress('start')->assertUnauthorized();
        $this->postJson('/api/v1/organizations', ['name' => 'X', 'request_id' => (string) Str::uuid()])->assertUnauthorized();
    }
}
