<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { catalogApi, type CatalogEntry } from '../../api/catalog';
import { productsApi } from '../../api/products';
import { connectionsApi, type Provider } from '../../api/connections';
import { marketplacesApi } from '../../api/marketplaces';
import type { Page, MarketplaceAccount } from '../../types';
import { message } from '../../api/client';
import { money } from '../../utils/money';
import PageState from '../ui/PageState.vue';
import PaginationBar from '../ui/PaginationBar.vue';
import ProductGallery from './ProductGallery.vue';
const providers = ref<Provider[]>([]);
const props = defineProps<{ accountId?: number; embedded?: boolean }>();
const route = useRoute(),
    router = useRouter(),
    result = ref<Page<CatalogEntry>>(),
    loading = ref(true),
    error = ref(''),
    accounts = ref<MarketplaceAccount[]>([]),
    deleting = ref<CatalogEntry>(),
    busy = ref(false),
    actionError = ref('');
const search = ref(''),
    provider = ref(''),
    account = ref(''),
    kind = ref(''),
    mapping = ref(''),
    active = ref(''),
    mode = ref(''),
    sort = ref('id'),
    direction = ref('asc'),
    page = ref(1),
    perPage = ref(20);
const accountOptions = computed(() =>
    accounts.value.filter((a) => !provider.value || a.marketplace === provider.value),
);
let syncing = false;
let timer: ReturnType<typeof setTimeout>,
    sequence = 0,
    disposed = false;
function readQuery() {
    syncing = true;
    const q = route.query;
    search.value = String(q.search || '');
    provider.value = String(q.marketplace || '');
    account.value = String(q.marketplace_account_id || '');
    kind.value = String(q.kind || '');
    mapping.value = String(q.mapping || '');
    active.value = String(q.is_active || '');
    mode.value = String(q.is_demo || '');
    sort.value = String(q.sort || 'id');
    direction.value = String(q.direction || 'asc');
    page.value = Number(q.page) || 1;
    perPage.value = Number(q.per_page) || 20;
    syncing = false;
}
function params() {
    return {
        search: search.value || undefined,
        marketplace: provider.value || undefined,
        marketplace_account_id: props.accountId || account.value || undefined,
        kind: kind.value || undefined,
        mapping: mapping.value || undefined,
        is_active: active.value || undefined,
        is_demo: mode.value || undefined,
        sort: sort.value,
        direction: direction.value,
        page: page.value,
        per_page: perPage.value,
    };
}
async function load() {
    const s = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const data = await catalogApi.list(params());
        if (s === sequence && !disposed) result.value = data;
    } catch (e) {
        if (s === sequence && !disposed) error.value = message(e);
    } finally {
        if (s === sequence && !disposed) loading.value = false;
    }
}
async function syncUrl() {
    const q = { ...route.query, ...params() };
    if (props.accountId) delete q.marketplace_account_id;
    await router.replace({ query: q });
}
readQuery();
watch(
    [search, provider, account, kind, mapping, active, mode, sort, direction, perPage],
    () => {
        if (syncing) return;
        clearTimeout(timer);
        timer = setTimeout(() => {
            page.value = 1;
            void syncUrl();
        }, 300);
    },
    { flush: 'sync' },
);
watch(
    () => route.fullPath,
    () => {
        clearTimeout(timer);
        readQuery();
        void load();
    },
);
watch(() => props.accountId, load);
function reset() {
    void router.replace({ query: props.embedded ? { tab: 'products' } : {} });
}
async function changePage(value: number) {
    page.value = value;
    await syncUrl();
}
function detail(entry: CatalogEntry) {
    return {
        path:
            entry.kind === 'local'
                ? `/app/products/${entry.entity_id}`
                : `/app/catalog/${entry.kind}/${entry.entity_id}`,
        query: { return_to: route.fullPath },
    };
}
async function remove() {
    if (!deleting.value) return;
    busy.value = true;
    try {
        await productsApi.remove(deleting.value.entity_id);
        deleting.value = undefined;
        await load();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
onMounted(async () => {
    void load();
    try {
        let n = 1,
            last = 1;
        const all: MarketplaceAccount[] = [];
        do {
            const data = await marketplacesApi.list({ page: n++, per_page: 100 });
            all.push(...data.data);
            last = data.meta.last_page;
        } while (n <= last && !disposed);
        accounts.value = all;
        providers.value = await connectionsApi.providers();
    } catch (e) {
        if (!disposed) error.value = message(e);
    }
});
onBeforeUnmount(() => {
    disposed = true;
    sequence++;
    clearTimeout(timer);
});
</script>
<template>
    <section class="panel catalog-panel" data-tour="product-list">
        <div class="section-heading padded">
            <div>
                <h2 data-tour="catalog-heading">Каталог товаров</h2>
                <p>{{ result?.meta.total ?? '—' }} товаров · локальные SKU и внешние источники</p>
            </div>
            <button class="text-link" @click="reset">Сбросить фильтры</button>
        </div>
        <div class="toolbar" data-tour="catalog-filters">
            <input
                v-model="search"
                data-tour="catalog-search"
                aria-label="Поиск товаров"
                placeholder="Название, SKU, barcode или внешний ID"
                maxlength="100"
            /><select v-model="provider" aria-label="Площадка">
                <option value="">Все площадки</option>
                <option v-for="p in providers" :key="p.code" :value="p.code">
                    {{ p.name }}
                </option></select
            ><select v-if="!accountId" v-model="account" aria-label="Подключение">
                <option value="">Все подключения</option>
                <option v-for="a in accountOptions" :key="a.id" :value="String(a.id)">
                    {{ a.name }}
                </option></select
            ><select v-model="kind" aria-label="Тип товара">
                <option value="">Все товары</option>
                <option value="local">Локальные</option>
                <option value="external">Внешние несопоставленные</option></select
            ><select v-model="mapping" data-tour="catalog-mapping" aria-label="Сопоставление">
                <option value="">Любое сопоставление</option>
                <option value="mapped">Сопоставленные</option>
                <option value="unmapped">Несопоставленные</option></select
            ><select v-model="active" aria-label="Активность">
                <option value="">Все статусы</option>
                <option value="1">Активные</option>
                <option value="0">Архивные</option></select
            ><select v-model="mode" aria-label="Происхождение">
                <option value="">Реальные и demo</option>
                <option value="0">Реальные</option>
                <option value="1">Демо</option></select
            ><select v-model="sort" aria-label="Сортировка">
                <option value="id">По ID</option>
                <option value="name">По названию</option>
                <option value="sku">По SKU</option>
                <option value="price_minor">По цене</option>
                <option value="created_at">По дате создания</option></select
            ><select v-model="direction" aria-label="Направление сортировки">
                <option value="asc">По возрастанию</option>
                <option value="desc">По убыванию</option>
            </select>
        </div>
        <PageState :loading="loading" :error="error" :empty="!result?.data.length" @retry="load"
            ><div class="table-scroll">
                <table class="catalog-table">
                    <thead>
                        <tr>
                            <th>Товар / SKU</th>
                            <th>Источники и подключения</th>
                            <th>Цена</th>
                            <th>Остатки</th>
                            <th>Статус / обновление</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in result?.data" :key="item.key">
                            <td>
                                <div class="catalog-identity">
                                    <ProductGallery
                                        :images="item.images"
                                        :title="item.title"
                                        thumbnail
                                    />
                                    <div>
                                        <RouterLink :to="detail(item)" class="product-name">{{
                                            item.title
                                        }}</RouterLink
                                        ><span class="table-subtitle mono">{{
                                            item.sku || 'Без SKU'
                                        }}</span
                                        ><span class="badge">{{
                                            item.kind === 'local'
                                                ? 'Локальный SKU'
                                                : 'Внешний · несопоставлен'
                                        }}</span>
                                    </div>
                                </div>
                            </td>
                            <td>
                                <div
                                    v-for="s in item.sources.slice(0, 4)"
                                    :key="s.id"
                                    class="catalog-source"
                                >
                                    <RouterLink
                                        :to="`/app/marketplaces/${s.account.id}?tab=products`"
                                        >{{ s.account.provider_name }} ·
                                        {{ s.account.name }}</RouterLink
                                    ><span v-if="s.account.is_demo" class="badge">Демо</span>
                                </div>
                                <span v-if="!item.sources.length" class="muted">Без размещений</span
                                ><RouterLink
                                    v-if="item.sources_count > 4"
                                    :to="detail(item)"
                                    class="text-link"
                                    >Все {{ item.sources_count }} размещений →</RouterLink
                                >
                            </td>
                            <td>
                                <strong>{{ money(item.price_minor, item.currency) }}</strong
                                ><span class="table-subtitle">{{
                                    item.kind === 'local' ? 'Цена MarketHub' : 'Цена источника · от'
                                }}</span>
                            </td>
                            <td>
                                <p v-if="item.kind === 'local'">
                                    MarketHub: {{ item.physical_quantity }} шт.
                                </p>
                                <p v-for="s in item.sources.slice(0, 2)" :key="s.id">
                                    {{ s.account.name }}:
                                    {{
                                        s.inventory.quantity === null
                                            ? 'Неизвестно'
                                            : `${s.inventory.quantity} шт.`
                                    }}<span
                                        v-if="s.inventory.current === false"
                                        class="table-subtitle"
                                        >Устаревший снимок</span
                                    ><span
                                        v-if="s.inventory.tracked === false"
                                        class="table-subtitle"
                                        >Не отслеживается</span
                                    >
                                </p>
                                <span v-if="item.sources_count > 2" class="table-subtitle"
                                    >Остальные — в карточке</span
                                >
                            </td>
                            <td>
                                <span
                                    class="badge"
                                    :class="item.status === 'active' ? 'success' : ''"
                                    >{{ item.status === 'active' ? 'Активен' : 'Архивный' }}</span
                                ><span class="table-subtitle">{{
                                    item.source_synced_at
                                        ? new Date(item.source_synced_at).toLocaleString('ru-RU')
                                        : 'Источник не синхронизирован'
                                }}</span>
                            </td>
                            <td>
                                <RouterLink :to="detail(item)" class="text-link"
                                    >Открыть →</RouterLink
                                >
                                <div v-if="item.kind === 'local'" class="row-actions">
                                    <RouterLink
                                        :to="`/app/products/${item.entity_id}/edit`"
                                        aria-label="Редактировать товар"
                                        class="text-link"
                                        >Изменить</RouterLink
                                    ><button
                                        class="text-link danger-text"
                                        aria-label="Удалить товар"
                                        @click="
                                            deleting = item;
                                            actionError = '';
                                        "
                                    >
                                        Удалить
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div></PageState
        >
        <div class="table-footer">
            <label
                >На странице<select v-model="perPage" aria-label="Товаров на странице">
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
    <div v-if="deleting" class="modal-backdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-label="Удаление товара">
            <h2>Удалить товар?</h2>
            <p>{{ deleting.title }}</p>
            <p v-if="actionError" class="alert">{{ actionError }}</p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" @click="deleting = undefined">
                    Отмена</button
                ><button class="danger" :disabled="busy" @click="remove">Удалить</button>
            </div>
        </section>
    </div>
</template>
<style scoped>
.catalog-identity {
    display: flex;
    align-items: center;
    gap: 16px;
    min-width: 270px;
}
.catalog-identity > div:last-child {
    min-width: 0;
}
.catalog-source {
    padding: 4px 0;
    max-width: 220px;
}
.catalog-table td {
    vertical-align: top;
}
.catalog-table p {
    margin: 4px 0;
}
.catalog-panel .toolbar > input {
    min-width: 240px;
}
.table-footer label {
    display: flex;
    gap: 8px;
    align-items: center;
}
@media (max-width: 600px) {
    .catalog-identity {
        min-width: 240px;
        gap: 12px;
    }
    .toolbar > input {
        width: 100%;
    }
}
</style>
