<script setup lang="ts">
import { onMounted, ref } from 'vue';
import { Package, CircleCheck, Store, TriangleAlert, ArrowUpRight } from 'lucide-vue-next';
import { productsApi } from '../api/products';
import { marketplacesApi } from '../api/marketplaces';
import { stocksApi } from '../api/stocks';
import { message } from '../api/client';
import PageState from '../components/ui/PageState.vue';
const loading = ref(true),
    error = ref(''),
    counts = ref<number[]>([]);
const cards = [
    { title: 'Всего товаров', icon: Package, to: '/app/products' },
    { title: 'Активные товары', icon: CircleCheck, to: '/app/products?is_active=1' },
    { title: 'Подключения', icon: Store, to: '/app/marketplaces' },
    { title: 'Размещения без остатка', icon: TriangleAlert, to: '/app/stocks?stock=zero' },
];
async function load() {
    loading.value = true;
    error.value = '';
    try {
        const results = await Promise.all([
            productsApi.list({ per_page: 1 }),
            productsApi.list({ per_page: 1, is_active: 1 }),
            marketplacesApi.list({ per_page: 1 }),
            stocksApi.list({ per_page: 1, stock: 'zero' }),
        ]);
        counts.value = results.map((r) => r.meta.total);
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">РАБОЧЕЕ ПРОСТРАНСТВО</p>
            <h1>Обзор</h1>
            <p>Главное о вашем каталоге прямо сейчас.</p>
        </div>
        <RouterLink to="/app/products/new" class="primary">+ Добавить товар</RouterLink>
    </div>
    <PageState :loading="loading" :error="error" @retry="load">
        <div class="stats-grid">
            <RouterLink v-for="(card, i) in cards" :key="card.title" :to="card.to" class="stat-card"
                ><div class="stat-top">
                    <component :is="card.icon" :size="21" /><ArrowUpRight :size="16" />
                </div>
                <strong>{{ counts[i]?.toLocaleString('ru-RU') }}</strong
                ><span>{{ card.title }}</span></RouterLink
            >
        </div>
        <div v-if="counts[0] === 0" class="panel state">
            <h2>Начните с первого товара</h2>
            <p>Создайте карточку, чтобы собрать ваш каталог.</p>
            <RouterLink to="/app/products/new" class="primary">Создать товар</RouterLink>
        </div>
    </PageState>
    <section class="panel dashboard-note">
        <div class="feature-icon">
            <Store :size="24" />
        </div>
        <div>
            <h2>Ваш каталог в одном месте</h2>
            <p>Создавайте товары, проверяйте остатки и управляйте подключениями маркетплейсов.</p>
            <p class="fine-print">
                Нулевой остаток считается по размещениям, а не по уникальным товарам. Финансовая
                аналитика и реальные интеграции пока недоступны.
            </p>
        </div>
        <RouterLink to="/app/stocks" class="text-link">К остаткам →</RouterLink>
    </section>
</template>
