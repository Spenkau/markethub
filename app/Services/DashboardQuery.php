<?php

namespace App\Services;

use App\Http\Requests\Api\V1\DashboardRequest;
use App\Models\MarketplaceAccount;
use App\Models\MarketplaceListing;
use App\Services\Marketplaces\ConnectionReadService;
use App\Services\Marketplaces\ProviderRegistry;
use App\Support\OrganizationContext;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DashboardQuery
{
    private const LOW = 5;

    public function __construct(private ConnectionReadService $connections, private ProviderRegistry $providers, private MarketplaceInventoryQuery $inventory) {}

    private function accounts(int $org, DashboardRequest $r, bool $mode = true): Builder
    {
        $q = DB::table('marketplace_accounts')->where('organization_id', $org);
        if ($r->filled('provider')) {
            $q->where('marketplace', $r->validated('provider'));
        }
        if ($r->filled('marketplace_account_id')) {
            $q->where('id', $r->integer('marketplace_account_id'));
        }
        if ($mode && ! $r->boolean('include_demo')) {
            $q->where('is_demo', false);
        }

        return $q;
    }

    private function listings(int $org, DashboardRequest $r): Builder
    {
        $q = DB::table('marketplace_listings as l')->where('l.organization_id', $org)
            ->whereIn('l.marketplace_account_id', $this->accounts($org, $r)->select('id'));
        if (! $r->boolean('include_demo')) {
            $q->where('l.origin', '!=', 'demo');
        }
        if ($r->filled('origin')) {
            $q->where('l.origin', $r->validated('origin'));
        }

        return $q;
    }

    private function counts(object $row): array
    {
        return array_map(fn ($v) => (int) $v, (array) $row);
    }

    public function overview(DashboardRequest $r): array
    {
        $org = OrganizationContext::id();
        abort_unless($org, 403);
        if ($r->filled('marketplace_account_id')) {
            abort_unless(DB::table('marketplace_accounts')->where('organization_id', $org)->where('id', $r->integer('marketplace_account_id'))->when($r->filled('provider'), fn ($q) => $q->where('marketplace', $r->validated('provider')))->exists(), 404);
        }
        $local = DB::table('products')->where('organization_id', $org)->count();
        $products = $this->counts($this->listings($org, $r)->join('marketplace_accounts as a', 'a.id', '=', 'l.marketplace_account_id')->selectRaw('count(*) filter(where a.is_demo) demo_variants,count(*) filter(where NOT a.is_demo) real_variants,count(*) external_variants, count(distinct l.marketplace_product_id) external_products, count(distinct l.product_id) represented_local_skus, count(*) filter(where l.product_id is null) unmapped_variants')->first());
        $products['local_skus'] = $local;
        $byProvider = $this->listings($org, $r)->join('marketplace_accounts as a', 'a.id', '=', 'l.marketplace_account_id')->groupBy('a.marketplace', 'a.is_demo', 'l.origin')->selectRaw('a.marketplace provider,a.is_demo,l.origin,count(*) variants,count(distinct l.marketplace_product_id) external_products,count(*) filter(where l.product_id is null) unmapped')->get()->map(fn ($v) => ['provider' => $v->provider, 'is_demo' => (bool) $v->is_demo, 'origin' => $v->origin, 'variants' => (int) $v->variants, 'external_products' => (int) $v->external_products, 'unmapped' => (int) $v->unmapped])->all();

        // An absent warehouse_stocks row means no recorded stock, not confirmed zero.
        $physicalByProduct = DB::table('warehouse_stocks')->where('organization_id', $org)->groupBy('product_id')->selectRaw('product_id,sum(quantity) quantity');
        $physical = $this->counts(DB::query()->fromSub($physicalByProduct, 'physical')->selectRaw('count(*) tracked_skus,count(*) filter(where quantity=0) zero_skus,count(*) filter(where quantity between 1 and 5) low_skus,coalesce(sum(quantity),0) units')->first());
        $physical['no_data_skus'] = $local - $physical['tracked_skus'];
        $physical['warehouses'] = DB::table('warehouses')->where('organization_id', $org)->where('is_active', true)->count();

        // Reuse TASK10's canonical levels join, never add the listing quantity cache.
        $rows = $this->inventory->snapshotRows(MarketplaceListing::query()->where('marketplace_listings.organization_id', $org)->whereIn('marketplace_listings.id', $this->listings($org, $r)->select('l.id')));
        $quantity = "CASE WHEN marketplace_listings.origin='imported' THEN inventory.available ELSE marketplace_listings.stock_quantity END";
        $valid = "marketplace_listings.status='active' AND CASE WHEN marketplace_listings.origin='imported' THEN coalesce(inventory.is_current AND inventory.tracked AND location.is_active AND inventory.available IS NOT NULL,false) ELSE marketplace_listings.stock_quantity IS NOT NULL END";
        $variants = $rows->select('marketplace_listings.id')->selectRaw("sum($quantity) quantity,count(inventory.id) snapshots,bool_or(marketplace_listings.origin='imported') imported,bool_and($valid) known,bool_or($quantity<0) negative,bool_or(inventory.tracked=false) untracked,bool_or(inventory.is_current=false OR location.is_active=false) stale")->groupBy('marketplace_listings.id')->toBase();
        $external = $this->counts(DB::query()->fromSub($variants, 'variants')->selectRaw('count(*) filter(where imported AND snapshots=0) no_snapshot_variants,count(*) variants,count(*) filter(where known AND NOT coalesce(negative,false) AND quantity between 1 and 5) low_variants,count(*) filter(where known AND NOT coalesce(negative,false) AND quantity=0) zero_variants,count(*) filter(where NOT known) unknown_variants,count(*) filter(where negative) negative_variants,count(*) filter(where untracked) untracked_variants,count(*) filter(where stale) stale_variants')->first());
        $levels = DB::table('marketplace_inventory_levels as i')->join('marketplace_locations as loc', 'loc.id', '=', 'i.marketplace_location_id')->where('i.organization_id', $org)->whereIn('i.marketplace_listing_id', $this->listings($org, $r)->select('l.id'));
        $external['stale_snapshots'] = (clone $levels)->where(fn ($q) => $q->where('i.is_current', false)->orWhere('loc.is_active', false))->count();
        $external['snapshots'] = $levels->count();

        $procurement = $this->counts(DB::table('purchase_orders')->where('organization_id', $org)->selectRaw("count(*) filter(where status in ('draft','ordered','partially_received')) open_orders,count(*) filter(where status='ordered') awaiting_receipt,count(*) filter(where status='partially_received') partially_received,count(*) filter(where status='draft') draft_orders")->first());
        $procurement['draft_receipts'] = DB::table('goods_receipts')->where('organization_id', $org)->where('status', 'draft')->count();

        $accountStats = $this->counts($this->accounts($org, $r)->selectRaw('count(*) total,count(*) filter(where is_demo) demo,count(*) filter(where NOT is_demo) real')->first());
        $accountStats['excluded_demo'] = $r->boolean('include_demo') ? 0 : $this->accounts($org, $r, false)->where('is_demo', true)->count();
        $accountStats['active_syncs'] = DB::table('marketplace_sync_runs')->where('organization_id', $org)->whereIn('marketplace_account_id', $this->accounts($org, $r)->select('id'))->whereIn('status', ['queued', 'running'])->count();
        $accountRows = $this->connectionRows($org, $r);
        // Aggregate all accounts, including those beyond the bounded connection preview.
        $latest = DB::table('marketplace_sync_runs')->where('organization_id', $org)->selectRaw('marketplace_account_id,status,row_number() over(partition by marketplace_account_id order by created_at desc,id desc) position');
        $accountStats['problems'] = $this->accounts($org, $r)->leftJoinSub($latest, 'latest', fn ($j) => $j->on('latest.marketplace_account_id', '=', 'marketplace_accounts.id')->where('latest.position', 1))->where('is_demo', false)->where('marketplace_accounts.status', 'active')->where(fn ($q) => $q->where('api_status', '!=', 'connected')->orWhereIn('latest.status', ['failed', 'partial']))->count();
        $accountStats['overdue_syncs'] = $this->accounts($org, $r)->where('is_demo', false)->where('status', 'active')->whereIn('sync_frequency', ['hourly', 'daily'])->where(fn ($q) => $q->where('sync_products', true)->orWhere('sync_inventory', true))->whereNotNull('initial_import_at')->whereRaw("coalesce(last_synced_at, initial_import_at) < ?::timestamptz - CASE WHEN sync_frequency='hourly' THEN interval '2 hours' ELSE interval '2 days' END", [now()->toIso8601String()])->count();

        $catalog = $this->link('/app/products', $r);
        $inventory = $this->link('/app/marketplace-stocks', $r);
        $attention = [];
        $add = function ($id, $count, $title, $description, $url, $severity = 'warning') use (&$attention) {
            if ($count > 0) {
                $attention[] = compact('id', 'count', 'title', 'description', 'url', 'severity');
            }
        };
        $connectionUrl = $r->filled('marketplace_account_id') ? '/app/marketplaces/'.$r->integer('marketplace_account_id') : $this->link('/app/marketplaces', $r);
        $add('connections', $accountStats['problems'], 'Подключения требуют проверки', 'Проблема доступа или последний запуск завершился ошибкой/частично.', $connectionUrl);
        $add('overdue', $accountStats['overdue_syncs'], 'Автоматическая синхронизация давно не завершалась', 'Более двух интервалов с последнего успешного импорта. Ручной режим сюда не входит.', $connectionUrl);
        $add('unmapped', $products['unmapped_variants'], 'Внешние варианты не сопоставлены', 'Свяжите варианты с локальными SKU; варианты и родительские карточки считаются отдельно.', $this->link('/app/products', $r, ['mapping' => 'unmapped']));
        $add('stale', $external['stale_snapshots'], 'Устаревшие внешние снимки', 'Показаны последние известные значения, а не подтверждённое текущее наличие.', $this->link('/app/marketplace-stocks', $r, ['is_current' => '0']));
        $add('external-low', $external['low_variants'], 'Мало внешнего остатка', 'Сумма известных актуальных остатков варианта по локациям — от 1 до 5. Склад MarketHub не включён.', $inventory);
        $add('physical-low', $physical['low_skus'] + $physical['zero_skus'], 'Физические SKU требуют пополнения', 'Суммарный учтённый остаток SKU на складах организации — от 0 до 5.', '/app/stocks');
        $add('receipts', $procurement['awaiting_receipt'], 'Закупки ожидают приёмки', 'Заказанные закупки всей организации.', '/app/purchases?status=ordered', 'info');
        $add('partial-receipts', $procurement['partially_received'], 'Частично принятые закупки', 'Осталось принять часть заказанных товаров.', '/app/purchases?status=partially_received', 'info');

        return [
            'generated_at' => now()->toIso8601String(),
            'scope' => ['organization_id' => $org, 'provider' => $r->validated('provider'), 'marketplace_account_id' => $r->filled('marketplace_account_id') ? $r->integer('marketplace_account_id') : null, 'include_demo' => $r->boolean('include_demo'), 'origin' => $r->validated('origin'), 'organization_wide' => ['local_skus', 'physical', 'procurement'], 'low_threshold' => self::LOW],
            'products' => $products, 'connections' => $accountStats, 'external_inventory' => $external, 'physical' => $physical, 'procurement' => $procurement,
            'connection_rows' => $accountRows, 'connections_truncated' => $accountStats['total'] > count($accountRows),
            'provider_distribution' => $byProvider, 'attention' => $attention, 'activity' => $this->activity($org, $r),
            'filters' => ['providers' => array_map(fn ($d) => ['code' => $d['code'], 'name' => $d['name'], 'is_demo' => $d['connection_type'] === 'demo'], $this->providers->definitions()), 'accounts' => DB::table('marketplace_accounts')->where('organization_id', $org)->orderBy('id')->limit(100)->get(['id', 'name', 'marketplace', 'is_demo'])->all()],
            'links' => ['catalog' => $catalog, 'inventory' => $inventory],
            'availability' => ['has_local_products' => $local > 0, 'has_physical_records' => $physical['tracked_skus'] > 0, 'has_external_snapshots' => $external['snapshots'] > 0, 'empty_organization' => $local === 0 && ! DB::table('marketplace_accounts')->where('organization_id', $org)->exists() && $physical['warehouses'] === 0 && $procurement['open_orders'] === 0],
        ];
    }

    private function link(string $path, DashboardRequest $r, array $extra = []): string
    {
        $params = array_filter(['marketplace' => $r->validated('provider'), 'marketplace_account_id' => $r->validated('marketplace_account_id'), 'origin' => $r->validated('origin'), ...$extra], fn ($v) => $v !== null && $v !== '');
        // Catalog/inventory accept is_demo=false; absence explicitly includes both modes.
        if (! $r->boolean('include_demo')) {
            $params['is_demo'] = '0';
        }

        return $path.($params ? '?'.http_build_query($params) : '');
    }

    private function connectionRows(int $org, DashboardRequest $r): array
    {
        $accounts = MarketplaceAccount::query()->where('organization_id', $org)->whereIn('id', $this->accounts($org, $r)->select('id'))->orderBy('id')->limit(50)->get(['id', 'organization_id', 'marketplace', 'name', 'is_demo', 'status', 'api_status', 'api_checked_at', 'sync_frequency', 'last_synced_at', 'next_sync_at', 'initial_import_at']);
        $this->connections->attach($accounts);

        return $accounts->map(function ($a) {
            $summary = $a->getRelation('syncSummary');
            $run = $summary['run'];
            $definition = $this->providers->get($a->marketplace)->definition();

            return ['id' => $a->id, 'name' => $a->name, 'provider' => $a->marketplace, 'provider_name' => $definition['name'], 'is_demo' => $a->is_demo, 'status' => $a->status,
                'api_status' => $a->is_demo ? 'demo' : $a->api_status, 'api_checked_at' => $a->api_checked_at?->toIso8601String(), 'sync_frequency' => $a->sync_frequency,
                'last_success_at' => $a->last_synced_at?->toIso8601String(), 'next_sync_at' => $a->next_sync_at?->toIso8601String(),
                'latest_run' => $run ? ['id' => $run->id, 'status' => $run->status, 'created_at' => $run->created_at?->toIso8601String()] : null,
                'variants' => (int) ($summary['stats']?->variants ?? 0), 'unmapped' => (int) ($summary['stats']?->unmapped ?? 0),
                'problem' => $a->is_demo || $a->status !== 'active' ? null : ($a->api_status !== 'connected' ? 'Доступ к API требует проверки.' : (in_array($run?->status, ['failed', 'partial'], true) ? 'Последняя синхронизация завершилась с ошибками.' : null)),
                'can_sync' => ! $a->is_demo && $a->status === 'active' && in_array('sync', $definition['capabilities'], true), 'needs_initial_confirmation' => ! $a->initial_import_at,
                'url' => '/app/marketplaces/'.$a->id, 'catalog_url' => '/app/products?marketplace_account_id='.$a->id, 'history_url' => in_array('sync', $definition['capabilities'], true) ? '/app/marketplaces/'.$a->id.'?tab=history' : null];
        })->all();
    }

    private function activity(int $org, DashboardRequest $r): array
    {
        $runs = DB::table('marketplace_sync_runs as s')->join('marketplace_accounts as a', 'a.id', '=', 's.marketplace_account_id')->where('s.organization_id', $org)->whereIn('a.id', $this->accounts($org, $r)->select('id'))->selectRaw("'sync' kind,s.id::text id,coalesce(s.finished_at,s.started_at,s.created_at) occurred_at,a.name source,s.status description,('/app/marketplaces/'||a.id||'?tab=history') url,a.is_demo");
        $movements = DB::table('stock_movements as m')->join('warehouses as w', 'w.id', '=', 'm.warehouse_id')->join('products as p', 'p.id', '=', 'm.product_id')->where('m.organization_id', $org)->selectRaw("'stock' kind,m.id::text id,m.occurred_at,w.name source,(p.name||' · '||m.type||' · '||m.quantity_delta) description,'/app/stocks?tab=movements' url,false is_demo");
        $receipts = DB::table('goods_receipts as g')->where('g.organization_id', $org)->where('g.status', 'confirmed')->selectRaw("'receipt' kind,g.id::text id,g.confirmed_at occurred_at,'Приёмка' source,'Подтверждена приёмка' description,('/app/purchases/receipts/'||g.id) url,false is_demo");

        // Only the latest 12 of each source can occur in the global latest 12.
        $recentRuns = DB::query()->fromSub($runs->orderByDesc('occurred_at')->orderByDesc('id')->limit(12), 'recent_runs');
        $recentMovements = DB::query()->fromSub($movements->orderByDesc('occurred_at')->orderByDesc('id')->limit(12), 'recent_movements');
        $recentReceipts = DB::query()->fromSub($receipts->orderByDesc('occurred_at')->orderByDesc('id')->limit(12), 'recent_receipts');

        return DB::query()->fromSub($recentRuns->unionAll($recentMovements)->unionAll($recentReceipts), 'events')->orderByDesc('occurred_at')->orderBy('kind')->orderByDesc('id')->limit(12)->get()->all();
    }
}
