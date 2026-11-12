<?php

namespace Tests\Feature;

use App\Jobs\SyncMarketplace;
use App\Jobs\SyncShopify;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Models\MarketplaceSyncRun;
use App\Models\Organization;
use App\Models\Product;
use App\Models\ShopifySyncRun;
use App\Models\User;
use App\Services\Marketplaces\SyncDispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Tests\Support\SyncTestProvider;
use Tests\TestCase;

class MarketplaceSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Organization $org;

    private MarketplaceAccount $account;

    protected function setUp(): void
    {
        parent::setUp();
        config(['marketplaces.providers.sync_test' => ['adapter' => SyncTestProvider::class, 'name' => 'Sync fixture']]);
        SyncTestProvider::$executions = 0;
        SyncTestProvider::$failures = 0;
        SyncTestProvider::$pauseMs = 0;
        SyncTestProvider::$unsafeFailure = false;
        Queue::fake();
        Http::preventStrayRequests();
        $this->org = Organization::factory()->create();
        $this->user = User::factory()->create(['active_organization_id' => $this->org->id]);
        $this->user->organizations()->attach($this->org);
        $this->actingAs($this->user);
        $this->account = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'sync_test', 'status' => 'active', 'is_demo' => false])->fresh();
    }

    private function url(string $suffix = ''): string
    {
        return '/api/v1/marketplace-accounts/'.$this->account->id.$suffix;
    }

    private function enqueue(): MarketplaceSyncRun
    {
        return app(SyncDispatcher::class)->enqueue($this->account, 'manual', null, true);
    }

    private function completed(): MarketplaceSyncRun
    {
        $run = $this->enqueue();
        (new SyncMarketplace($run->id))->handle();

        return $run->fresh();
    }

    private function listing(): MarketplaceListing
    {
        $product = Product::factory()->create(['organization_id' => $this->org->id]);

        return MarketplaceListing::factory()->create(['organization_id' => $this->org->id, 'marketplace_account_id' => $this->account->id, 'product_id' => $product->id]);
    }

    public function test_initial_confirmation_uuid_history_and_status_are_persisted(): void
    {
        $this->postJson($this->url('/sync'))->assertUnprocessable();
        $id = $this->postJson($this->url('/sync'), ['confirm_initial_import' => true])->assertAccepted()->assertJsonPath('data.scope', 'account')->assertJsonPath('data.trigger', 'initial')->json('data.id');
        Queue::assertPushed(SyncMarketplace::class, 1);
        $this->getJson($this->url('/sync-status'))->assertOk()->assertJsonPath('data.active_run.id', $id);
        (new SyncMarketplace($id))->handle();
        $this->getJson($this->url('/sync-runs/'.$id))->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.account_name', $this->account->name)->assertJsonPath('data.attempt', 1);
        $this->getJson($this->url('/sync-runs'))->assertJsonPath('meta.total', 1);
        $this->getJson($this->url('/sync-status'))->assertJsonPath('data.active_run', null);
        $this->assertNotNull($this->account->fresh()->last_synced_at);
    }

    public function test_hourly_daily_and_manual_schedule_respect_saved_settings(): void
    {
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->completed();
        $this->travel(61)->minutes();
        $this->assertSame(1, app(SyncDispatcher::class)->schedule());
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $run = MarketplaceSyncRun::where('status', 'queued')->firstOrFail();
        $this->assertSame('scheduled', $run->trigger);
        (new SyncMarketplace($run->id))->handle();
        $this->patchJson($this->url(), ['sync_frequency' => 'daily'])->assertOk();
        $this->travel(23)->hours();
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->travel(2)->hours();
        $this->assertSame(1, app(SyncDispatcher::class)->schedule());
        (new SyncMarketplace(MarketplaceSyncRun::where('status', 'queued')->firstOrFail()->id))->handle();
        $this->patchJson($this->url(), ['sync_frequency' => 'manual'])->assertOk();
        $this->travel(2)->days();
        $this->assertSame(0, app(SyncDispatcher::class)->schedule());
        $this->assertNull($this->account->fresh()->next_sync_at);
    }

    public function test_flags_are_independent_and_demo_never_gets_a_fake_run(): void
    {
        $this->account->forceFill(['sync_products' => false, 'sync_inventory' => true])->save();
        $run = $this->completed();
        $this->assertSame(0, $run->variants_processed);
        $this->assertSame(1, $run->inventory_processed);
        $this->account->forceFill(['sync_products' => true, 'sync_inventory' => false])->save();
        $run = $this->completed();
        $this->assertSame(1, $run->variants_processed);
        $this->assertSame(0, $run->inventory_processed);
        $this->account->forceFill(['sync_products' => false, 'sync_inventory' => false])->save();
        $this->postJson($this->url('/sync'))->assertUnprocessable();
        $demo = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id, 'marketplace' => 'ozon', 'status' => 'demo', 'is_demo' => true]);
        $this->postJson('/api/v1/marketplace-accounts/'.$demo->id.'/sync', ['confirm_initial_import' => true])->assertUnprocessable();
        $this->assertSame(0, MarketplaceSyncRun::where('marketplace_account_id', $demo->id)->count());
    }

    public function test_manual_and_scheduled_deduplicate_and_point_conflicts_with_full_scope(): void
    {
        $this->completed();
        $this->travel(61)->minutes();
        $manual = $this->enqueue();
        $scheduled = app(SyncDispatcher::class)->enqueue($this->account, 'scheduled', null, true);
        $this->assertSame($manual->id, $scheduled->id);
        $listing = $this->listing();
        $this->postJson('/api/v1/marketplace-listings/'.$listing->id.'/refresh')->assertConflict();
        $this->assertSame(1, MarketplaceSyncRun::whereIn('status', ['queued', 'running'])->count());
    }

    public function test_variant_scope_never_becomes_full_reconcile_or_advances_account_watermarks(): void
    {
        $this->completed();
        $account = $this->account->fresh();
        $listing = $this->listing();
        $id = $this->postJson('/api/v1/marketplace-listings/'.$listing->id.'/refresh')->assertAccepted()->assertJsonPath('data.scope', 'variant')->assertJsonPath('data.full_reconcile', false)->json('data.id');
        (new SyncMarketplace($id))->handle();
        $this->assertEquals($account->last_synced_at, $account->fresh()->last_synced_at);
        $this->assertEquals($account->next_sync_at, $account->fresh()->next_sync_at);
        $this->assertDatabaseHas('marketplace_listings', ['id' => $listing->id, 'product_id' => $listing->product_id]);
    }

    public function test_duplicate_delivery_and_serialized_legacy_job_execute_exactly_once(): void
    {
        $run = $this->enqueue();
        $payload = serialize(new SyncShopify($run->id));
        $this->assertStringNotContainsString('client_secret', $payload);
        unserialize($payload)->handle();
        (new SyncMarketplace($run->id))->handle();
        $this->assertSame(1, SyncTestProvider::$executions);
        $this->assertSame(1, $run->fresh()->attempt);
        $this->assertSame($run->id, ShopifySyncRun::findOrFail($run->id)->id);
        $this->assertSame($run->id, DB::table('shopify_sync_runs')->value('id'));
    }

    public function test_redis_publication_failure_stays_pending_and_reconciles_without_another_run(): void
    {
        $queue = Queue::getFacadeRoot();
        Queue::swap(new class
        {
            public function connection($name)
            {
                return $this;
            }

            public function push(...$args)
            {
                throw new \RuntimeException('SECRET-REDIS-CONNECTION');
            }
        });
        try {
            $run = $this->enqueue();
        } finally {
            Queue::swap($queue);
        }
        $this->assertSame('queued', $run->status);
        $this->assertSame('pending', $run->dispatch_state);
        $this->assertStringNotContainsString('SECRET-REDIS-CONNECTION', json_encode($run));
        $this->travel(61)->seconds();
        $this->assertSame(1, app(SyncDispatcher::class)->recover());
        $this->assertSame('published', $run->fresh()->dispatch_state);
        $this->assertDatabaseCount('marketplace_sync_runs', 1);
        Queue::assertPushed(SyncMarketplace::class, 1);
    }

    public function test_recovery_handles_manual_initial_queued_running_and_abandoned_publish_claims(): void
    {
        $this->account->forceFill(['sync_frequency' => 'manual'])->save();
        $run = $this->enqueue();
        $run->forceFill(['dispatched_at' => now()->subMinutes(10)])->save();
        $this->assertSame(1, app(SyncDispatcher::class)->recover());
        $run->forceFill(['status' => 'running', 'attempt' => 1, 'heartbeat_at' => now()->subMinutes(10), 'started_at' => now()->subMinutes(10)])->save();
        $this->assertSame(1, app(SyncDispatcher::class)->recover());
        $this->assertSame('queued', $run->fresh()->status);
        $run->forceFill(['dispatch_state' => 'publishing', 'dispatch_lease_at' => now()->subMinutes(10)])->save();
        $this->assertSame(1, app(SyncDispatcher::class)->recover());
        $this->assertDatabaseCount('marketplace_sync_runs', 1);
        $this->assertNull($this->account->fresh()->initial_import_at);
    }

    public function test_old_heartbeat_does_not_recover_a_worker_that_still_holds_its_execution_lock(): void
    {
        $run = $this->enqueue();
        $run->forceFill(['status' => 'running', 'attempt' => 1, 'heartbeat_at' => now()->subMinutes(10)])->save();
        $c = config('database.connections.pgsql');
        $pdo = new \PDO('pgsql:host='.$c['host'].';port='.$c['port'].';dbname='.$c['database'], $c['username'], $c['password']);
        $stmt = $pdo->prepare('SELECT pg_advisory_lock(hashtextextended(CAST(? AS text),0))');
        $stmt->execute([SyncDispatcher::lockKey($this->account->id)]);
        try {
            $this->assertSame(0, app(SyncDispatcher::class)->recover());
            $this->assertSame('running', $run->fresh()->status);
        } finally {
            $pdo->prepare('SELECT pg_advisory_unlock(hashtextextended(CAST(? AS text),0))')->execute([SyncDispatcher::lockKey($this->account->id)]);
        }
    }

    public function test_retry_has_unique_final_counters_and_clears_current_error_but_keeps_attempt_diagnostics(): void
    {
        SyncTestProvider::$failures = 1;
        $run = $this->enqueue();
        (new SyncMarketplace($run->id))->handle();
        $this->assertSame('queued', $run->fresh()->status);
        $this->assertSame(1, $run->fresh()->created);
        $this->travel(61)->seconds();
        app(SyncDispatcher::class)->recover();
        (new SyncMarketplace($run->id))->handle();
        $run->refresh();
        $this->assertSame('completed', $run->status);
        $this->assertSame(2, $run->attempt);
        $this->assertSame(2, $run->processed);
        $this->assertSame(1, $run->created);
        $this->assertSame(1, $run->variants_processed);
        $this->assertSame(1, $run->inventory_processed);
        $this->assertSame(0, $run->errors_count);
        $this->assertNull($run->error_message);
        $this->assertCount(1, $run->attempt_errors);
        $this->assertSame('replay_from_start', $run->checkpoint['strategy']);
        $this->assertDatabaseCount('marketplace_sync_results', 3);
    }

    public function test_bounded_attempts_and_sanitized_provider_failures(): void
    {
        $messages = [];
        Log::listen(function ($event) use (&$messages) {
            $messages[] = $event->message;
        });
        SyncTestProvider::$unsafeFailure = true;
        $run = $this->enqueue();
        (new SyncMarketplace($run->id))->handle();
        $this->assertSame('partial', $run->fresh()->status);
        $this->assertStringNotContainsString('SECRET-DO-NOT-LOG', json_encode([$run->fresh(), $messages]));
        SyncTestProvider::$unsafeFailure = false;
        SyncTestProvider::$failures = 10;
        $retry = $this->enqueue();
        for ($i = 0; $i < 3; $i++) {
            (new SyncMarketplace($retry->id))->handle();
            $this->travel(301)->seconds();
        }
        $this->assertSame(3, $retry->fresh()->attempt);
        $this->assertSame('partial', $retry->fresh()->status);
    }

    public function test_runs_targets_and_status_are_scoped_to_account_and_organization(): void
    {
        $run = $this->enqueue();
        $listing = $this->listing();
        $other = MarketplaceAccount::factory()->create(['organization_id' => $this->org->id]);
        $this->getJson('/api/v1/marketplace-accounts/'.$other->id.'/sync-runs/'.$run->id)->assertNotFound();
        $foreign = User::factory()->create();
        $this->actingAs($foreign);
        foreach (['/sync-status', '/sync-runs', '/sync-runs/'.$run->id] as $suffix) {
            $this->getJson($this->url($suffix))->assertNotFound();
        }
        $this->postJson($this->url('/sync'), ['confirm_initial_import' => true])->assertNotFound();
        $this->postJson('/api/v1/marketplace-listings/'.$listing->id.'/refresh')->assertNotFound();
        $this->assertNull(MarketplaceSyncRun::first());
    }

    public function test_minsk_database_timezone_does_not_shift_next_hour_or_recovery_deadline(): void
    {
        DB::statement("SET TIME ZONE 'Europe/Minsk'");
        try {
            $this->freezeTime();
            $run = $this->completed();
            $this->assertSame(now()->timestamp, $run->finished_at->timestamp);
            $this->assertSame(now()->addHour()->timestamp, $this->account->fresh()->next_sync_at->timestamp);
            $this->travel(61)->minutes();
            $this->assertSame(1, app(SyncDispatcher::class)->schedule());
        } finally {
            DB::statement("SET TIME ZONE 'UTC'");
        }
    }
}
