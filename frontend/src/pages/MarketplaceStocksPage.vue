<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Search } from 'lucide-vue-next';
import { stocksApi } from '../api/stocks';
import { downloadExcel } from '../api/exports';
import { message } from '../api/client';
import { money } from '../utils/money';
import type { Page, Stock } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
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
        marketplace_account_id: route.query.marketplace_account_id
            ? String(route.query.marketplace_account_id)
            : undefined,
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
watch([search, marketplace, stock, status, sort, direction, perPage], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
watch(
    () => [route.query.product_id, route.query.marketplace_account_id],
    () => {
        page.value = 1;
        void load();
    },
);
function resetFilters() {
    search.value = '';
    marketplace.value = '';
    stock.value = '';
    status.value = '';
    void router.replace({ query: {} });
}
onMounted(load);
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
    <div class="page-heading">
        <div>
            <p class="eyebrow">НАЛИЧИЕ НА МАРКЕТПЛЕЙСАХ</p>
            <h1>Остатки маркетплейсов</h1>
            <p>Остатки размещений учитываются отдельно от физического складского наличия.</p>
        </div>
        <span class="badge">Только просмотр</span>
    </div>
    <p v-if="exportError" class="alert" role="alert">{{ exportError }}</p>
    <div class="list-summary">
        <div>
            <span class="summary-icon"><Search :size="19" /></span>
            <div>
                <strong>{{ result?.meta.total ?? '—' }}</strong
                ><span>Размещений по условиям</span>
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
                <h2>Наличие по размещениям</h2>
                <p>Порог низкого остатка — 5 единиц</p>
            </div>
            <button
                v-if="
                    search ||
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
                <option value="wildberries">Wildberries</option>
                <option value="ozon">Ozon</option>
                <option value="shopify">Shopify</option></select
            ><select v-model="stock" aria-label="Остаток">
                <option value="">Любой остаток</option>
                <option value="zero">Нет в наличии</option>
                <option value="low">Мало: 1–5 шт.</option>
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
        <PageState :loading="loading" :error="error" :empty="!result?.data.length" @retry="load">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Товар / SKU</th>
                            <th>Маркетплейс</th>
                            <th>Остаток</th>
                            <th>Цена</th>
                            <th>Статус</th>
                            <th>Действие</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in result?.data" :key="item.id">
                            <td>
                                <RouterLink
                                    :to="
                                        item.marketplace_account.marketplace === 'shopify'
                                            ? `/app/marketplaces/shopify/${item.marketplace_account_id}/products/${item.id}`
                                            : `/app/products/${item.product?.id}`
                                    "
                                    class="product-name"
                                    >{{
                                        item.product?.name || item.source_title || 'Не сопоставлен'
                                    }}</RouterLink
                                ><span class="table-subtitle mono">{{
                                    item.product?.sku || 'Без SKU'
                                }}</span>
                            </td>
                            <td>
                                <strong>{{
                                    item.marketplace_account.marketplace === 'shopify'
                                        ? 'Shopify'
                                        : item.marketplace_account.marketplace === 'ozon'
                                          ? 'Ozon'
                                          : 'Wildberries'
                                }}</strong
                                ><span class="table-subtitle">{{
                                    item.marketplace_account.name
                                }}</span>
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
                                        item.stock_quantity === null
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
                                <RouterLink
                                    :to="
                                        item.marketplace_account.marketplace === 'shopify'
                                            ? `/app/marketplaces/shopify/${item.marketplace_account_id}/products/${item.id}`
                                            : `/app/products/${item.product?.id}`
                                    "
                                    class="text-link nowrap"
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
                >На странице<select v-model="perPage" aria-label="Размещений на странице">
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
