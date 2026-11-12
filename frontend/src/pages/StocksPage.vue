<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    ArrowDownToLine,
    ArrowUpFromLine,
    ArrowLeftRight,
    ClipboardCheck,
    Plus,
    Search,
    Warehouse as WarehouseIcon,
    Layers,
    Boxes,
    History,
    Pencil,
    RefreshCw,
} from 'lucide-vue-next';
import {
    inventoryApi,
    operationNames,
    type Warehouse,
    type WarehouseStock,
    type StockMovement,
    type InventorySummary,
    type OperationType,
} from '../api/inventory';
import { message } from '../api/client';
import type { Page } from '../types';
import { shortDate } from '../utils/date';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
import ResourcePicker from '../components/inventory/ResourcePicker.vue';
import WarehouseForm from '../components/inventory/WarehouseForm.vue';
import StockOperationForm from '../components/inventory/StockOperationForm.vue';
const route = useRoute(),
    router = useRouter();
type Tab = 'stocks' | 'warehouses' | 'movements';
function currentTab(): Tab {
    return route.query.tab === 'warehouses' || route.query.tab === 'movements'
        ? route.query.tab
        : 'stocks';
}
const tab = ref<Tab>(currentTab()),
    search = ref(''),
    warehouseFilter = ref<number | null>(
        route.query.warehouse_id ? Number(route.query.warehouse_id) : null,
    ),
    stockFilter = ref(''),
    warehouseType = ref(''),
    warehouseActive = ref(''),
    movementType = ref(''),
    dateFrom = ref(''),
    dateTo = ref(''),
    sort = ref('id'),
    direction = ref('asc'),
    page = ref(1),
    perPage = ref(20);
const stocks = ref<Page<WarehouseStock>>(),
    warehouses = ref<Page<Warehouse>>(),
    movements = ref<Page<StockMovement>>(),
    summary = ref<InventorySummary>();
const savedWarehouse = ref<Warehouse>();
const selectedWarehouse = ref<Warehouse>();
const loading = ref(true),
    error = ref(''),
    notice = ref(''),
    showWarehouse = ref(false),
    editing = ref<Warehouse>(),
    operation = ref<OperationType>();
const meta = computed(() =>
    tab.value === 'stocks'
        ? stocks.value?.meta
        : tab.value === 'warehouses'
          ? warehouses.value?.meta
          : movements.value?.meta,
);
const actions = [
    { type: 'receipt' as const, icon: ArrowDownToLine },
    { type: 'write_off' as const, icon: ArrowUpFromLine },
    { type: 'transfer' as const, icon: ArrowLeftRight },
    { type: 'inventory' as const, icon: ClipboardCheck },
];
let sequence = 0;
let timer: ReturnType<typeof setTimeout>;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    const common = { page: page.value, per_page: perPage.value, search: search.value || undefined };
    try {
        const totals = inventoryApi.summary();
        // Attach both handlers immediately, so failures never leave unhandled promises.
        const records =
            tab.value === 'stocks'
                ? inventoryApi.stocks({
                      ...common,
                      warehouse_id: warehouseFilter.value ?? undefined,
                      stock: stockFilter.value || undefined,
                      product_id: route.query.product_id
                          ? String(route.query.product_id)
                          : undefined,
                      sort: sort.value,
                      direction: direction.value,
                  })
                : tab.value === 'warehouses'
                  ? inventoryApi.warehouses({
                        ...common,
                        type: warehouseType.value || undefined,
                        is_active: warehouseActive.value || undefined,
                    })
                  : inventoryApi.movements({
                        ...common,
                        warehouse_id: warehouseFilter.value ?? undefined,
                        type: movementType.value || undefined,
                        date_from: dateFrom.value || undefined,
                        date_to: dateTo.value || undefined,
                        product_id: route.query.product_id
                            ? String(route.query.product_id)
                            : undefined,
                        operation_id: route.query.operation_id
                            ? String(route.query.operation_id)
                            : undefined,
                    });
        const [data, metrics] = await Promise.all([records, totals]);
        if (current !== sequence) return;
        summary.value = metrics;
        if (tab.value === 'stocks') stocks.value = data as Page<WarehouseStock>;
        else if (tab.value === 'warehouses') warehouses.value = data as Page<Warehouse>;
        else movements.value = data as Page<StockMovement>;
    } catch (e) {
        if (current === sequence) {
            error.value = message(e);
            summary.value = undefined;
        }
    } finally {
        if (current === sequence) loading.value = false;
    }
}
function schedule() {
    sequence++;
    loading.value = true;
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 250);
}
watch(
    [
        search,
        warehouseFilter,
        stockFilter,
        warehouseType,
        warehouseActive,
        movementType,
        dateFrom,
        dateTo,
        sort,
        direction,
        perPage,
    ],
    schedule,
);
watch(tab, () => {
    page.value = 1;
    search.value = '';
    notice.value = '';
    void router.replace({
        query: { ...route.query, tab: tab.value === 'stocks' ? undefined : tab.value },
    });
    schedule();
});
watch(
    () => route.query.tab,
    () => {
        tab.value = currentTab();
    },
);
watch(() => [route.query.product_id, route.query.operation_id], schedule);
onMounted(load);
onBeforeUnmount(() => {
    clearTimeout(timer);
    sequence++;
});
function edit(warehouse?: Warehouse) {
    editing.value = warehouse;
    showWarehouse.value = true;
}
function reset() {
    search.value = '';
    warehouseFilter.value = null;
    stockFilter.value = '';
    warehouseType.value = '';
    warehouseActive.value = '';
    movementType.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    void router.replace({ query: { tab: tab.value === 'stocks' ? undefined : tab.value } });
}
async function warehouseSaved(value: Warehouse) {
    await saved();
    savedWarehouse.value = value;
}
watch(
    () => route.query.warehouse_id,
    (value) => {
        warehouseFilter.value = value ? Number(value) : null;
    },
);
watch(
    warehouseFilter,
    async (value) => {
        selectedWarehouse.value = undefined;
        if (value) {
            try {
                selectedWarehouse.value = await inventoryApi.warehouse(value);
            } catch {
                /* A foreign or removed warehouse is never expanded. */
            }
        }
    },
    { immediate: true },
);
async function saved() {
    savedWarehouse.value = undefined;
    showWarehouse.value = false;
    operation.value = undefined;
    notice.value = 'Изменения сохранены.';
    await load();
}
function movementDate(value: string) {
    return new Date(value).toLocaleString('ru-RU', {
        day: '2-digit',
        month: 'short',
        hour: '2-digit',
        minute: '2-digit',
    });
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">СКЛАДСКОЙ УЧЁТ</p>
            <h1>Склады и остатки</h1>
            <p>Физическое наличие, перемещения и прозрачная история каждого изменения.</p>
        </div>
        <div class="heading-actions">
            <button
                class="secondary"
                :disabled="loading"
                aria-label="Обновить складские данные"
                @click="load"
            >
                <RefreshCw :size="16" /></button
            ><button class="primary" data-tour="create-warehouse" @click="edit()">
                <Plus :size="17" />Новый склад
            </button>
        </div>
    </div>
    <TourLauncher tour-id="warehouses" /><RouterLink
        to="/app/marketplaces?marketplace=shopify"
        class="text-link"
        >Внешние остатки Shopify — отдельно от физических →</RouterLink
    >
    <div class="stats-grid inventory-stats">
        <div class="stat-card">
            <div class="stat-top">
                <span class="metric-icon"><WarehouseIcon :size="20" /></span>
            </div>
            <span>Активные склады</span><strong>{{ summary?.active_warehouses ?? '—' }}</strong
            ><small>Из {{ summary?.warehouses ?? '—' }} складов организации</small>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <span class="metric-icon"><Boxes :size="20" /></span>
            </div>
            <span>Единиц на складах</span
            ><strong>{{ summary?.units.toLocaleString('ru-RU') ?? '—' }}</strong
            ><small>Все склады · без остатков площадок</small>
        </div>
        <div class="stat-card">
            <div class="stat-top">
                <span class="metric-icon"><Layers :size="20" /></span>
            </div>
            <span>Товаров в учёте</span><strong>{{ summary?.products ?? '—' }}</strong
            ><small>{{ summary?.positions ?? '—' }} складских позиций</small>
        </div>
        <div class="stat-card amber">
            <div class="stat-top">
                <span class="metric-icon"><History :size="20" /></span>
            </div>
            <span>Нулевые позиции</span><strong>{{ summary?.zero_positions ?? '—' }}</strong
            ><small>{{ summary?.low_positions ?? '—' }} с остатком 1–5 шт.</small>
        </div>
    </div>
    <div class="inventory-actions" data-tour="warehouse-operations">
        <button
            v-for="action in actions"
            :key="action.type"
            class="secondary"
            @click="operation = action.type"
        >
            <component :is="action.icon" :size="16" />{{ operationNames[action.type] }}</button
        ><RouterLink to="/app/marketplace-stocks" class="text-link"
            >Остатки маркетплейсов →</RouterLink
        >
    </div>
    <div v-if="savedWarehouse" class="next-action-banner" role="status">
        <span>Склад «{{ savedWarehouse.name }}» сохранён.</span
        ><RouterLink to="/app/products/new" class="text-link">Добавить товар →</RouterLink
        ><RouterLink
            :to="`/app/purchases?create=purchase&warehouse_id=${savedWarehouse.id}`"
            class="text-link"
            >Создать закупку →</RouterLink
        >
    </div>
    <p v-if="notice" class="success-banner" role="status">{{ notice }}</p>
    <section class="panel" data-tour="warehouse-list">
        <div class="inventory-tabs" role="tablist" aria-label="Складской учёт">
            <button
                id="tab-stocks"
                role="tab"
                :aria-selected="tab === 'stocks'"
                aria-controls="inventory-content"
                @click="tab = 'stocks'"
            >
                <Layers :size="16" />Остатки</button
            ><button
                id="tab-warehouses"
                role="tab"
                :aria-selected="tab === 'warehouses'"
                aria-controls="inventory-content"
                @click="tab = 'warehouses'"
            >
                <WarehouseIcon :size="16" />Склады</button
            ><button
                id="tab-movements"
                role="tab"
                :aria-selected="tab === 'movements'"
                aria-controls="inventory-content"
                @click="tab = 'movements'"
            >
                <History :size="16" />История движений
            </button>
        </div>
        <div class="toolbar inventory-toolbar">
            <div class="search-input">
                <Search :size="17" /><input
                    v-model="search"
                    :placeholder="
                        tab === 'warehouses' ? 'Название или код склада' : 'Название товара или SKU'
                    "
                    aria-label="Поиск складского учёта"
                    maxlength="100"
                />
            </div>
            <template v-if="tab !== 'warehouses'"
                ><ResourcePicker
                    v-model="warehouseFilter"
                    class="filter-picker"
                    kind="warehouses"
                    label="Фильтр по складу"
                    :selected-option="
                        selectedWarehouse
                            ? { id: selectedWarehouse.id, label: selectedWarehouse.name }
                            : undefined
                    " /></template
            ><template v-if="tab === 'stocks'"
                ><select v-model="stockFilter" aria-label="Складской остаток">
                    <option value="">Любой остаток</option>
                    <option value="zero">Нулевой остаток</option>
                    <option value="low">Мало · 1–5 шт.</option></select
                ><select v-model="sort" aria-label="Сортировка складских остатков">
                    <option value="id">По ID</option>
                    <option value="quantity">По количеству</option>
                    <option value="updated_at">По обновлению</option></select
                ><select v-model="direction" aria-label="Направление сортировки">
                    <option value="asc">По возрастанию</option>
                    <option value="desc">По убыванию</option>
                </select></template
            ><template v-else-if="tab === 'warehouses'"
                ><select v-model="warehouseType" aria-label="Фильтр типа склада">
                    <option value="">Все типы</option>
                    <option value="own">Собственные</option>
                    <option value="marketplace">Склады маркетплейсов</option></select
                ><select v-model="warehouseActive" aria-label="Активность склада">
                    <option value="">Все склады</option>
                    <option value="1">Активные</option>
                    <option value="0">Отключённые</option>
                </select></template
            ><template v-else
                ><select v-model="movementType" aria-label="Тип движения">
                    <option value="">Все операции</option>
                    <option v-for="(label, value) in operationNames" :key="value" :value="value">
                        {{ label }}
                    </option></select
                ><label class="date-filter"
                    >С даты (UTC)<input
                        v-model="dateFrom"
                        type="date"
                        aria-label="Движения с даты" /></label
                ><label class="date-filter"
                    >По дату (UTC)<input
                        v-model="dateTo"
                        type="date"
                        aria-label="Движения по дату" /></label></template
            ><button class="text-link" @click="reset">Сбросить фильтры</button>
        </div>
        <div
            v-if="route.query.product_id || route.query.operation_id || route.query.warehouse_id"
            class="filter-context"
        >
            <span v-if="route.query.product_id">Товар #{{ route.query.product_id }}</span
            ><span v-if="route.query.operation_id">Операция {{ route.query.operation_id }}</span>
        </div>
        <div id="inventory-content" role="tabpanel" :aria-labelledby="`tab-${tab}`">
            <PageState :loading="loading" :error="error" :empty="!meta?.total" @retry="load"
                ><template #emptyActions
                    ><button v-if="tab === 'warehouses'" class="secondary" @click="edit()">
                        Добавить первый склад</button
                    ><RouterLink v-else to="/app/products" class="text-link"
                        >Проверить каталог товаров →</RouterLink
                    ></template
                >
                <div v-if="tab === 'stocks'" class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Товар / SKU</th>
                                <th>Склад</th>
                                <th>Остаток</th>
                                <th>Обновлён</th>
                                <th>Действия</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in stocks?.data" :key="row.id">
                                <td>
                                    <RouterLink
                                        :to="`/app/products/${row.product.id}`"
                                        class="product-name"
                                        >{{ row.product.name }}</RouterLink
                                    ><span class="table-subtitle mono">{{ row.product.sku }}</span>
                                </td>
                                <td>
                                    {{ row.warehouse.name
                                    }}<span class="table-subtitle"
                                        >{{ row.warehouse.code }} ·
                                        {{
                                            row.warehouse.type === 'own'
                                                ? 'Собственный'
                                                : 'Маркетплейс'
                                        }}<span v-if="!row.warehouse.is_active">
                                            · Отключён</span
                                        ></span
                                    >
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            row.quantity === 0
                                                ? 'critical'
                                                : row.quantity <= 5
                                                  ? 'warning'
                                                  : 'success'
                                        "
                                        >{{ row.quantity }} шт.</span
                                    >
                                </td>
                                <td class="nowrap muted">{{ shortDate(row.updated_at) }}</td>
                                <td>
                                    <RouterLink
                                        :to="{
                                            path: '/app/stocks',
                                            query: { tab: 'movements', product_id: row.product_id },
                                        }"
                                        class="text-link"
                                        >История →</RouterLink
                                    ><RouterLink
                                        v-if="row.warehouse.is_active"
                                        :to="`/app/purchases?create=purchase&product_id=${row.product_id}&warehouse_id=${row.warehouse_id}`"
                                        class="table-subtitle text-link"
                                        >Создать закупку →</RouterLink
                                    >
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else-if="tab === 'warehouses'" class="warehouse-grid">
                    <article
                        v-for="warehouse in warehouses?.data"
                        :key="warehouse.id"
                        class="warehouse-card"
                    >
                        <div class="account-top">
                            <span class="feature-icon"><WarehouseIcon :size="22" /></span
                            ><span class="badge" :class="warehouse.is_active ? 'success' : ''">{{
                                warehouse.is_active ? 'Активен' : 'Отключён'
                            }}</span>
                        </div>
                        <h2>{{ warehouse.name }}</h2>
                        <p class="mono">{{ warehouse.code }}</p>
                        <dl>
                            <dt>Тип</dt>
                            <dd>{{ warehouse.type === 'own' ? 'Собственный' : 'Маркетплейс' }}</dd>
                            <dt>Учётных позиций</dt>
                            <dd>{{ warehouse.stocks_count ?? 0 }}</dd>
                        </dl>
                        <p v-if="warehouse.type === 'marketplace'" class="fine-print">
                            Учёт вручную · без API-интеграции
                        </p>
                        <button class="secondary full" @click="edit(warehouse)">
                            <Pencil :size="14" />Редактировать склад
                        </button>
                    </article>
                </div>
                <div v-else class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Дата / операция</th>
                                <th>Товар / склад</th>
                                <th>Изменение</th>
                                <th>До → после</th>
                                <th>Пользователь / комментарий</th>
                                <th>Документ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="row in movements?.data" :key="row.id">
                                <td>
                                    <strong>{{ operationNames[row.type] }}</strong
                                    ><span class="table-subtitle nowrap">{{
                                        movementDate(row.occurred_at)
                                    }}</span
                                    ><RouterLink
                                        :to="{
                                            path: '/app/stocks',
                                            query: {
                                                tab: 'movements',
                                                operation_id: row.operation_id,
                                            },
                                        }"
                                        class="table-subtitle mono"
                                        :title="row.operation_id"
                                        >{{ row.operation_id.slice(0, 8) }} ↗</RouterLink
                                    >
                                </td>
                                <td>
                                    <RouterLink
                                        :to="`/app/products/${row.product.id}`"
                                        class="product-name"
                                        >{{ row.product.name }}</RouterLink
                                    ><span class="table-subtitle"
                                        >{{ row.product.sku }} · {{ row.warehouse.name }}</span
                                    >
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="
                                            row.quantity_delta > 0
                                                ? 'success'
                                                : row.quantity_delta < 0
                                                  ? 'critical'
                                                  : ''
                                        "
                                        >{{ row.quantity_delta > 0 ? '+' : ''
                                        }}{{ row.quantity_delta }}</span
                                    >
                                </td>
                                <td class="nowrap">
                                    {{ row.quantity_before }} → {{ row.quantity_after }}
                                </td>
                                <td>
                                    {{ row.user.name
                                    }}<span class="table-subtitle movement-comment">{{
                                        row.comment || 'Без комментария'
                                    }}</span>
                                </td>
                                <td>
                                    <RouterLink
                                        v-if="row.document"
                                        :to="`/app/purchases/receipts/${row.document.id}`"
                                        class="text-link nowrap"
                                        >Приёмка #{{ row.document.id }} →</RouterLink
                                    ><span v-else class="muted">Ручная операция</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div></PageState
            >
        </div>
        <div v-if="meta" class="table-footer">
            <label class="page-size"
                >На странице<select v-model="perPage">
                    <option :value="20">20</option>
                    <option :value="50">50</option>
                    <option :value="100">100</option>
                </select></label
            ><PaginationBar
                :meta="meta"
                :disabled="loading"
                @change="
                    page = $event;
                    load();
                "
            />
        </div>
    </section>
    <p class="fine-print inventory-footnote">
        Нулевая позиция появляется после операции, обнулившей остаток. Товары, которые ещё не
        учитывались на складе, не создают фиктивных строк. Сводка относится ко всем складам
        организации.
    </p>
    <WarehouseForm
        v-if="showWarehouse"
        :warehouse="editing"
        @close="showWarehouse = false"
        @saved="warehouseSaved"
    />
    <StockOperationForm
        v-if="operation"
        :type="operation"
        @close="operation = undefined"
        @saved="saved"
    />
</template>
