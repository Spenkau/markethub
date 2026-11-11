<script setup lang="ts">
import { onMounted, onBeforeUnmount, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import { Search, Plus, Pencil, Trash2, Package as PackageIcon } from 'lucide-vue-next';
import { productsApi } from '../api/products';
import { message } from '../api/client';
import { money } from '../utils/money';
import type { Page, Product } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
const route = useRoute(),
    search = ref(''),
    active = ref(String(route.query.is_active || '')),
    sort = ref('id'),
    direction = ref('asc'),
    page = ref(1),
    result = ref<Page<Product>>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    deleting = ref<Product | null>(null),
    busy = ref(false);
let timer: ReturnType<typeof setTimeout>;
let sequence = 0;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const data = await productsApi.list({
            page: page.value,
            search: search.value || undefined,
            is_active: active.value || undefined,
            sort: sort.value,
            direction: direction.value,
        });
        if (current === sequence) result.value = data;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
watch([search, active, sort, direction], () => {
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
        <RouterLink to="/app/products/new" class="primary"
            ><Plus :size="18" />Добавить товар</RouterLink
        >
    </div>
    <section class="panel">
        <div class="toolbar">
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
        <PageState :loading="loading" :error="error" :empty="!result?.data.length" @retry="load">
            <div class="table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Товар</th>
                            <th>SKU</th>
                            <th>Цена</th>
                            <th>Статус</th>
                            <th>Размещения</th>
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
                            <td>{{ product.listings_count ?? '—' }}</td>
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
            </div> </PageState
        ><PaginationBar
            v-if="result"
            :meta="result.meta"
            :disabled="loading"
            @change="changePage"
        />
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
