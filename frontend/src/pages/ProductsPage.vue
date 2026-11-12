<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { computed, onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Search, Plus, Pencil, Trash2, Package as PackageIcon } from 'lucide-vue-next';
import { productsApi } from '../api/products';
import { downloadExcel } from '../api/exports';
import { message } from '../api/client';
import { shortDate } from '../utils/date';
import { money } from '../utils/money';
import type { Page, Product } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
const route = useRoute(),
    search = ref(''),
    active = ref(String(route.query.is_active || '')),
    sort = ref('id'),
    perPage = ref(20),
    direction = ref('asc'),
    page = ref(1),
    result = ref<Page<Product>>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    deleting = ref<Product | null>(null),
    busy = ref(false);

const createdProduct = ref<Product>();
async function loadCreated() {
    createdProduct.value = undefined;
    if (route.query.created) {
        try {
            createdProduct.value = await productsApi.get(String(route.query.created));
        } catch {
            /* Ignore invalid context; never invent a saved product. */
        }
    }
}
onMounted(loadCreated);
watch(() => route.query.created, loadCreated);
const exporting = ref(false),
    exportError = ref('');
function filters() {
    return {
        search: search.value || undefined,
        is_active: active.value || undefined,
        sort: sort.value,
        direction: direction.value,
    };
}
async function exportExcel() {
    if (exporting.value) return;
    exporting.value = true;
    exportError.value = '';
    try {
        await downloadExcel('products', filters());
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
        const data = await productsApi.list({
            page: page.value,
            per_page: perPage.value,
            ...filters(),
        });
        if (current === sequence) result.value = data;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
const pageActive = computed(() => result.value?.data.filter((p) => p.is_active).length ?? 0);
function resetFilters() {
    search.value = '';
    active.value = '';
    sort.value = 'id';
    direction.value = 'asc';
}
watch([search, active, sort, direction, perPage], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
onMounted(load);
onBeforeUnmount(() => {
    clearTimeout(timer);
    sequence++;
});
async function changePage(value: number) {
    page.value = value;
    await load();
}
async function remove() {
    if (!deleting.value || busy.value) return;
    busy.value = true;
    actionError.value = '';
    try {
        await productsApi.remove(deleting.value.id);
        deleting.value = null;
        if (result.value?.data.length === 1 && page.value > 1) page.value--;
        await load();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">КАТАЛОГ</p>
            <h1>
                Товары <span class="count">{{ result?.meta.total ?? '—' }}</span>
            </h1>
            <p>Управляйте ассортиментом и ценами.</p>
        </div>
        <RouterLink to="/app/products/new" class="primary" data-tour="create-product"
            ><Plus :size="18" />Добавить товар</RouterLink
        >
    </div>
    <TourLauncher tour-id="products" />
    <p v-if="exportError" class="alert" role="alert">{{ exportError }}</p>
    <div class="list-summary">
        <div>
            <span class="summary-icon"><PackageIcon :size="19" /></span>
            <div>
                <strong>{{ result?.meta.total ?? '—' }}</strong
                ><span>Товаров по условиям</span>
            </div>
        </div>
        <div>
            <strong>{{ loading ? '—' : pageActive }}</strong
            ><span>Активных на странице</span>
        </div>
        <div>
            <strong>{{
                loading ? '—' : (result?.data.filter((p) => !p.listings_count).length ?? 0)
            }}</strong
            ><span>Без размещений на странице</span>
        </div>
    </div>
    <div v-if="createdProduct" class="next-action-banner" role="status">
        <span>Товар «{{ createdProduct.name }}» сохранён. Что дальше?</span
        ><RouterLink :to="`/app/stocks?product_id=${createdProduct.id}`" class="text-link"
            >К складским остаткам →</RouterLink
        ><RouterLink
            :to="`/app/purchases?create=purchase&product_id=${createdProduct.id}`"
            class="text-link"
            >Создать закупку →</RouterLink
        >
    </div>
    <section class="panel" data-tour="product-list">
        <div class="section-heading padded">
            <div>
                <h2>Каталог товаров</h2>
                <p>Цены, публикации и состояние ассортимента</p>
            </div>
            <button v-if="search || active" class="text-link" @click="resetFilters">
                Сбросить фильтры
            </button>
        </div>
        <div class="toolbar">
            <button class="secondary" :disabled="exporting" @click="exportExcel">
                {{ exporting ? 'Экспортируем…' : 'Экспорт в Excel' }}
            </button>
            <div class="search-input">
                <Search :size="18" /><input
                    v-model="search"
                    placeholder="Название или SKU"
                    aria-label="Поиск товаров"
                    maxlength="100"
                />
            </div>
            <select v-model="active" aria-label="Активность">
                <option value="">Все статусы</option>
                <option value="1">Активные</option>
                <option value="0">Неактивные</option></select
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
            ><template #emptyActions
                ><RouterLink to="/app/products/new" class="secondary"
                    >Создать первый товар</RouterLink
                ></template
            >
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Товар</th>
                            <th>SKU</th>
                            <th>Цена</th>
                            <th>Статус</th>
                            <th>Размещения</th>
                            <th>Обновлён</th>
                            <th><span class="sr-only">Действия</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="product in result?.data" :key="product.id">
                            <td>
                                <RouterLink :to="`/app/products/${product.id}`" class="product-name"
                                    ><span class="product-icon"><PackageIcon /></span
                                    >{{ product.name }}</RouterLink
                                >
                            </td>
                            <td class="mono muted">
                                {{ product.sku }}
                            </td>
                            <td class="nowrap">
                                {{ money(product.price_minor, product.currency) }}
                            </td>
                            <td>
                                <span class="badge" :class="product.is_active ? 'success' : ''">{{
                                    product.is_active ? 'Активен' : 'Неактивен'
                                }}</span>
                            </td>
                            <td>
                                <RouterLink
                                    :to="`/app/marketplace-stocks?product_id=${product.id}`"
                                    class="badge"
                                    :class="product.listings_count ? 'success' : 'warning'"
                                    >{{
                                        product.listings_count
                                            ? `${product.listings_count} размещений`
                                            : 'Не размещён'
                                    }}</RouterLink
                                >
                            </td>
                            <td class="nowrap muted">{{ shortDate(product.updated_at) }}</td>
                            <td>
                                <div class="row-actions">
                                    <RouterLink
                                        :to="`/app/products/${product.id}/edit`"
                                        class="icon-button"
                                        aria-label="Редактировать товар"
                                        ><Pencil :size="16" /></RouterLink
                                    ><button
                                        class="icon-button danger-text"
                                        aria-label="Удалить товар"
                                        @click="
                                            deleting = product;
                                            actionError = '';
                                        "
                                    >
                                        <Trash2 :size="16" />
                                    </button>
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </PageState>
        <div class="table-footer">
            <label class="page-size"
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
    <div v-if="deleting" class="modal-backdrop" @keydown.esc="!busy && (deleting = null)">
        <section class="modal" role="alertdialog" aria-modal="true" aria-labelledby="delete-title">
            <h2 id="delete-title">Удалить товар?</h2>
            <p>«{{ deleting.name }}» будет удалён. Это действие нельзя отменить.</p>
            <p v-if="actionError" class="alert" role="alert">
                {{ actionError }}
            </p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" @click="deleting = null">Отмена</button
                ><button class="danger" :disabled="busy" @click="remove">
                    {{ busy ? 'Удаляем…' : 'Удалить' }}
                </button>
            </div>
        </section>
    </div>
</template>
