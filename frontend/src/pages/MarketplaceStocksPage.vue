<script setup lang="ts">
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Search } from 'lucide-vue-next';
import { connectionsApi, type Provider } from '../api/connections';
import { marketplacesApi } from '../api/marketplaces';
import { inventoryApi, type Warehouse } from '../api/inventory';
import type { MarketplaceAccount } from '../types';
import type { ExternalLocation } from '../api/stocks';
import { stocksApi } from '../api/stocks';
import { downloadExcel } from '../api/exports';
import { message } from '../api/client';
import { money } from '../utils/money';
import type { Page, Stock } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
const props = defineProps<{ accountId?: number; embedded?: boolean }>();
const router = useRouter();
const route = useRoute(),
    search = ref(''),
    marketplace = ref(String(route.query.marketplace || '')),
    status = ref(''),
    sort = ref('stock_quantity'),
    direction = ref('asc'),
    perPage = ref(20),
    stock = ref(String(route.query.stock || '')),
    page = ref(1),
    result = ref<Page<Stock>>(),
    loading = ref(true),
    error = ref('');

const providers = ref<Provider[]>([]);
function productUrl(item: Stock) {
    return item.inventory_origin === 'external_snapshot' || !item.product
        ? `/app/catalog/external_variant/${item.id}`
        : `/app/products/${item.product.id}`;
}
const accounts = ref<MarketplaceAccount[]>([]),
    locations = ref<ExternalLocation[]>([]),
    warehouses = ref<Warehouse[]>([]);
const accountId = ref(String(props.accountId || route.query.marketplace_account_id || '')),
    locationId = ref(''),
    current = ref(String(route.query.is_current || '')),
    mapping = ref(''),
    warehouseId = ref(''),
    mappingBusy = ref(false);
const visibleAccounts = computed(() =>
    accounts.value.filter((a) => !marketplace.value || a.marketplace === marketplace.value),
);
const selectedLocation = computed(() =>
    locations.value.find((l) => String(l.id) === locationId.value),
);
let locationSequence = 0;
async function loadLocations() {
    const id = ++locationSequence;
    locations.value = [];
    locationId.value = '';
    if (!accountId.value) return;
    try {
        const data = await stocksApi.locations(Number(accountId.value));
        if (id === locationSequence) locations.value = data;
    } catch (e) {
        error.value = message(e);
    }
}
async function mapWarehouse() {
    const target = selectedLocation.value;
    if (!target) return;
    mappingBusy.value = true;
    try {
        await stocksApi.mapLocation(
            target.id,
            warehouseId.value ? Number(warehouseId.value) : null,
        );
        target.warehouse_id = warehouseId.value ? Number(warehouseId.value) : null;
    } catch (e) {
        error.value = message(e);
    } finally {
        mappingBusy.value = false;
    }
}
watch(selectedLocation, (l) => {
    warehouseId.value = l?.warehouse_id ? String(l.warehouse_id) : '';
});
watch(accountId, loadLocations);
watch(
    () => props.accountId,
    (value) => {
        accountId.value = String(value || '');
        void load();
    },
);
watch(marketplace, () => {
    if (!visibleAccounts.value.some((a) => String(a.id) === accountId.value)) accountId.value = '';
});
async function loadOptions() {
    try {
        providers.value = await connectionsApi.providers();
        let pageNumber = 1,
            last = 1;
        const all: MarketplaceAccount[] = [];
        do {
            const response = await marketplacesApi.list({ per_page: 100, page: pageNumber++ });
            all.push(...response.data);
            last = response.meta.last_page;
        } while (pageNumber <= last);
        accounts.value = all;
        let warehousePage = 1,
            warehouseLast = 1;
        const local: Warehouse[] = [];
        do {
            const response = await inventoryApi.warehouses({
                per_page: 100,
                page: warehousePage++,
            });
            local.push(...response.data);
            warehouseLast = response.meta.last_page;
        } while (warehousePage <= warehouseLast);
        warehouses.value = local;
        await loadLocations();
    } catch (e) {
        error.value = message(e);
    }
}
const exporting = ref(false),
    exportError = ref('');
function filters() {
    return {
        search: search.value || undefined,
        marketplace: marketplace.value || undefined,
        stock: stock.value || undefined,
        status: status.value || undefined,
        sort: sort.value,
        direction: direction.value,
        marketplace_account_id: props.accountId || accountId.value || undefined,
        location_id: locationId.value || undefined,
        is_current: current.value || undefined,
        is_demo: route.query.is_demo ? String(route.query.is_demo) : undefined,
        origin: route.query.origin ? String(route.query.origin) : undefined,
        mapping: mapping.value || undefined,
        product_id: route.query.product_id ? String(route.query.product_id) : undefined,
    };
}
async function exportExcel() {
    if (exporting.value) return;
    exporting.value = true;
    exportError.value = '';
    try {
        await downloadExcel('stocks', filters());
    } catch (e) {
        exportError.value = message(e);
    } finally {
        exporting.value = false;
    }
}

let timer: ReturnType<typeof setTimeout>;
let sequence = 0;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const response = await stocksApi.list({
            page: page.value,
            per_page: perPage.value,
            ...filters(),
        });
        if (current === sequence) result.value = response;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
watch(
    [
        search,
        marketplace,
        stock,
        status,
        sort,
        direction,
        perPage,
        accountId,
        locationId,
        current,
        mapping,
    ],
    () => {
        page.value = 1;
        clearTimeout(timer);
        timer = setTimeout(load, 300);
    },
);
watch(
    () => [
        route.query.product_id,
        route.query.marketplace_account_id,
        route.query.marketplace,
        route.query.stock,
        route.query.is_current,
        route.query.is_demo,
        route.query.origin,
    ],
    () => {
        accountId.value = String(props.accountId || route.query.marketplace_account_id || '');
        marketplace.value = String(route.query.marketplace || '');
        stock.value = String(route.query.stock || '');
        current.value = String(route.query.is_current || '');
        page.value = 1;
        void load();
    },
);
function resetFilters() {
    search.value = '';
    marketplace.value = '';
    stock.value = '';
    status.value = '';
    accountId.value = props.accountId ? String(props.accountId) : '';
    locationId.value = '';
    current.value = '';
    mapping.value = '';
    void router.replace({ query: props.embedded ? { tab: 'inventory' } : {} });
}
onMounted(() => {
    void load();
    void loadOptions();
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    sequence++;
});
async function changePage(value: number) {
    page.value = value;
    await load();
}
</script>
<template>
    <div v-if="!embedded" class="page-heading">
        <div>
            <p class="eyebrow">НАЛИЧИЕ НА МАРКЕТПЛЕЙСАХ</p>
            <h1>Остатки маркетплейсов</h1>
            <p>Остатки размещений учитываются отдельно от физического складского наличия.</p>
        </div>
        <span class="badge">Внешние снимки</span>
    </div>
    <p v-if="exportError" class="alert" role="alert">{{ exportError }}</p>
    <div class="list-summary">
        <div>
            <span class="summary-icon"><Search :size="19" /></span>
            <div>
                <strong>{{ result?.meta.total ?? '—' }}</strong
                ><span>Позиций по условиям</span>
            </div>
        </div>
        <div>
            <strong>{{
                loading ? '—' : (result?.data.filter((i) => i.stock_quantity === 0).length ?? 0)
            }}</strong
            ><span>Без остатка на странице</span>
        </div>
        <div>
            <strong>{{
                loading
                    ? '—'
                    : (result?.data.filter(
                          (i) =>
                              i.stock_quantity !== null &&
                              i.stock_quantity > 0 &&
                              i.stock_quantity <= 5,
                      ).length ?? 0)
            }}</strong
            ><span>Мало на странице · 1–5 шт.</span>
        </div>
    </div>
    <section class="panel" data-tour="marketplace-stock-list">
        <div class="section-heading padded">
            <div>
                <h2 data-tour="inventory-heading">Наличие по внешним локациям</h2>
                <p>Порог низкого остатка — 5 единиц</p>
            </div>
            <button
                v-if="
                    search ||
                    locationId ||
                    current ||
                    mapping ||
                    (!props.accountId && accountId) ||
                    marketplace ||
                    stock ||
                    status ||
                    route.query.product_id ||
                    route.query.marketplace_account_id
                "
                class="text-link"
                @click="resetFilters"
            >
                Сбросить фильтры
            </button>
        </div>
        <div
            v-if="route.query.product_id || route.query.marketplace_account_id"
            class="filter-context"
        >
            Выбрано: <span v-if="route.query.product_id">товар #{{ route.query.product_id }}</span
            ><span v-if="route.query.marketplace_account_id"
                >подключение #{{ route.query.marketplace_account_id }}</span
            >
        </div>
        <div class="toolbar">
            <button class="secondary" :disabled="exporting" @click="exportExcel">
                {{ exporting ? 'Экспортируем…' : 'Экспорт в Excel' }}
            </button>
            <div class="search-input">
                <Search :size="18" /><input
                    v-model="search"
                    placeholder="Название, SKU или внешний ID"
                    aria-label="Поиск остатков"
                    maxlength="100"
                />
            </div>
            <select v-model="marketplace" aria-label="Маркетплейс">
                <option value="">Все маркетплейсы</option>
                <option v-for="p in providers" :key="p.code" :value="p.code">
                    {{ p.name }}
                </option></select
            ><select v-model="stock" aria-label="Остаток">
                <option value="">Любой остаток</option>
                <option value="zero">Нет в наличии</option>
                <option value="low">Мало: 1–5 шт.</option>
                <option value="unknown">Неизвестно</option>
                <option value="negative">Отрицательный</option>
            </select>
            <select v-model="accountId" aria-label="Подключение" :disabled="!!props.accountId">
                <option value="">Все подключения</option>
                <option v-for="a in visibleAccounts" :key="a.id" :value="String(a.id)">
                    {{ a.name }}
                </option>
            </select>
            <select v-model="locationId" aria-label="Внешняя локация" :disabled="!accountId">
                <option value="">Все локации подключения</option>
                <option v-for="l in locations" :key="l.id" :value="String(l.id)">
                    {{ l.name }}{{ l.is_active ? '' : ' (отключена)' }}
                </option>
            </select>
            <select v-model="current" aria-label="Актуальность">
                <option value="">Любая актуальность</option>
                <option value="1">Актуальные снимки</option>
                <option value="0">Устаревшие снимки</option>
            </select>
            <select v-model="mapping" aria-label="Сопоставление товара">
                <option value="">Любое сопоставление</option>
                <option value="mapped">Сопоставленные</option>
                <option value="unmapped">Без сопоставления</option>
            </select>
            <select v-model="status" aria-label="Статус размещения">
                <option value="">Все статусы</option>
                <option value="active">Активные</option>
                <option value="inactive">Неактивные</option>
            </select>
            <select v-model="sort" aria-label="Сортировка остатков">
                <option value="stock_quantity">По остатку</option>
                <option value="price_minor">По цене</option>
                <option value="created_at">По дате создания</option>
            </select>
            <select v-model="direction" aria-label="Направление сортировки">
                <option value="asc">По возрастанию</option>
                <option value="desc">По убыванию</option>
            </select>
        </div>
        <div v-if="selectedLocation" class="toolbar" data-demo-write>
            <label
                >Локальный склад для {{ selectedLocation.name }}
                <select v-model="warehouseId" aria-label="Сопоставление склада">
                    <option value="">Без сопоставления</option>
                    <option v-for="w in warehouses" :key="w.id" :value="String(w.id)">
                        {{ w.name }}
                    </option>
                </select></label
            >
            <button class="secondary" :disabled="mappingBusy" @click="mapWarehouse">
                Сохранить связь
            </button>
            <span>Информационная связь. Физические остатки не изменяются.</span>
        </div>
        <PageState :loading="loading" :error="error" :empty="!result?.data.length" @retry="load">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Товар / SKU</th>
                            <th>Маркетплейс</th>
                            <th>Локация / источник</th>
                            <th>Остаток</th>
                            <th>Цена</th>
                            <th>Статус</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr
                            v-for="item in result?.data"
                            :key="`${item.id}:${item.inventory_level_id ?? 'summary'}`"
                        >
                            <td>
                                <RouterLink :to="productUrl(item)" class="product-name">{{
                                    item.product?.name || item.source_title || 'Не сопоставлен'
                                }}</RouterLink
                                ><span class="table-subtitle mono">{{
                                    item.external_sku || item.product?.sku || 'Без SKU'
                                }}</span>
                            </td>
                            <td>
                                <strong>{{
                                    providers.find(
                                        (p) => p.code === item.marketplace_account.marketplace,
                                    )?.name || item.marketplace_account.marketplace
                                }}</strong
                                ><span class="table-subtitle">{{
                                    item.marketplace_account.name
                                }}</span>
                            </td>
                            <td>
                                {{ item.location_name || 'Без детализации'
                                }}<span class="table-subtitle">{{
                                    item.inventory_origin === 'demo' ||
                                    item.marketplace_account.is_demo
                                        ? 'Демо'
                                        : 'Внешний снимок'
                                }}</span
                                ><span v-if="item.location_active === false" class="table-subtitle"
                                    >Локация отключена</span
                                >
                            </td>
                            <td>
                                <span
                                    class="stock-count"
                                    :class="
                                        item.stock_quantity === 0
                                            ? 'zero'
                                            : item.stock_quantity !== null &&
                                                item.stock_quantity <= 5
                                              ? 'low'
                                              : ''
                                    "
                                    >{{
                                        item.tracked === false
                                            ? 'Не отслеживается'
                                            : item.stock_quantity === null
                                              ? 'Неизвестно'
                                              : `${item.stock_quantity} шт.`
                                    }}</span
                                ><span
                                    v-if="item.stock_quantity !== null && item.stock_quantity <= 5"
                                    class="table-subtitle"
                                    >{{
                                        item.stock_quantity === 0
                                            ? 'Нет в наличии'
                                            : 'Низкий остаток'
                                    }}</span
                                >
                                <span v-if="item.is_current === false" class="table-subtitle"
                                    >Устаревший снимок</span
                                ><span
                                    v-if="item.inventory_source_synced_at"
                                    class="table-subtitle"
                                    >{{
                                        new Date(item.inventory_source_synced_at).toLocaleString(
                                            'ru-RU',
                                        )
                                    }}</span
                                >
                            </td>
                            <td class="nowrap">
                                {{ money(item.price_minor, item.currency) }}
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="item.status === 'active' ? 'success' : ''"
                                    >{{ item.status === 'active' ? 'Активно' : 'Неактивно' }}</span
                                >
                            </td>
                            <td>
                                <RouterLink :to="productUrl(item)" class="text-link nowrap"
                                    >Карточка →</RouterLink
                                >
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <div class="table-footer">
            <label class="page-size"
                >На странице<select v-model="perPage" aria-label="Позиций на странице">
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select></label
            ><PaginationBar
                v-if="result"
                :meta="result.meta"
                :disabled="loading"
                @change="changePage"
            />
        </div>
    </section>
</template>
