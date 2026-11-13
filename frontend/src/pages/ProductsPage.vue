<script setup lang="ts">
import { ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import MarketplaceProductList from '../components/catalog/MarketplaceProductList.vue';
import { productsApi } from '../api/products';
import type { Product } from '../types';
const route = useRoute(),
    createdProduct = ref<Product>();
watch(
    () => route.query.created,
    async (id) => {
        createdProduct.value = undefined;
        if (id)
            try {
                createdProduct.value = await productsApi.get(String(id));
            } catch {
                /* Invalid context is not a saved product. */
            }
    },
    { immediate: true },
);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">ЕДИНЫЙ КАТАЛОГ</p>
            <h1>Товары</h1>
            <p>Локальные SKU, внешние товары и размещения всех подключений.</p>
        </div>
        <RouterLink
            to="/app/products/new"
            class="primary"
            data-tour="create-product"
            data-demo-write
            >Добавить товар</RouterLink
        >
    </div>
    <TourLauncher tour-id="products" />
    <div v-if="createdProduct" class="next-action-banner" role="status">
        <span>Товар «{{ createdProduct.name }}» сохранён. Что дальше?</span
        ><RouterLink :to="`/app/stocks?product_id=${createdProduct.id}`" class="text-link"
            >К складским остаткам →</RouterLink
        ><RouterLink
            :to="`/app/purchases?create=purchase&product_id=${createdProduct.id}`"
            class="text-link"
            data-demo-write
            >Создать закупку →</RouterLink
        >
    </div>
    <MarketplaceProductList />
</template>
