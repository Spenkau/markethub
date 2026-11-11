<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute } from 'vue-router';
import { Search } from 'lucide-vue-next';
import { stocksApi } from '../api/stocks';
import { message } from '../api/client';
import { money } from '../utils/money';
import type { Page, Stock } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
const route = useRoute(),
    search = ref(''),
    marketplace = ref(''),
    stock = ref(String(route.query.stock || '')),
    page = ref(1),
    result = ref<Page<Stock>>(),
    loading = ref(true),
    error = ref('');
let timer: ReturnType<typeof setTimeout>;
let sequence = 0;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const response = await stocksApi.list({
            page: page.value,
            search: search.value || undefined,
            marketplace: marketplace.value || undefined,
            stock: stock.value || undefined,
            product_id: route.query.product_id ? String(route.query.product_id) : undefined,
        });
        if (current === sequence) result.value = response;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
watch([search, marketplace, stock], () => {
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
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">НАЛИЧИЕ НА МАРКЕТПЛЕЙСАХ</p>
            <h1>Остатки</h1>
            <p>Количество и цены по каждому размещению.</p>
        </div>
        <span class="badge">Только просмотр</span>
    </div>
    <section class="panel">
        <div class="toolbar">
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
                <option value="ozon">Ozon</option></select
            ><select v-model="stock" aria-label="Остаток">
                <option value="">Любой остаток</option>
                <option value="zero">Нет в наличии</option>
                <option value="low">Мало: 1–5 шт.</option>
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
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="item in result?.data" :key="item.id">
                            <td>
                                <RouterLink
                                    :to="`/app/products/${item.product.id}`"
                                    class="product-name"
                                    >{{ item.product.name }}</RouterLink
                                ><span class="table-subtitle mono">{{ item.product.sku }}</span>
                            </td>
                            <td>
                                <strong>{{
                                    item.marketplace_account.marketplace === 'ozon'
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
                                            : item.stock_quantity <= 5
                                              ? 'low'
                                              : ''
                                    "
                                    >{{ item.stock_quantity }} шт.</span
                                ><span v-if="item.stock_quantity <= 5" class="table-subtitle">{{
                                    item.stock_quantity === 0 ? 'Нет в наличии' : 'Низкий остаток'
                                }}</span>
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
</template>
