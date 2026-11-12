<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { ref, onMounted } from 'vue';
import { useRoute } from 'vue-router';
import { productsApi } from '../api/products';
import { message } from '../api/client';
import { money } from '../utils/money';
import type { Product } from '../types';
import PageState from '../components/ui/PageState.vue';
const route = useRoute(),
    product = ref<Product>(),
    loading = ref(true),
    error = ref('');
async function load() {
    loading.value = true;
    error.value = '';
    try {
        product.value = await productsApi.get(String(route.params.id));
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
</script>
<template>
    <TourLauncher tour-id="products" />
    <RouterLink to="/app/products" class="text-link">← Все товары</RouterLink
    ><PageState :loading="loading" :error="error" @retry="load">
        <template v-if="product">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">КАРТОЧКА ТОВАРА · #{{ product.id }}</p>
                    <h1>{{ product.name }}</h1>
                    <p>{{ product.sku }}</p>
                </div>
                <RouterLink :to="`/app/products/${product.id}/edit`" class="primary"
                    >Редактировать</RouterLink
                >
            </div>
            <div class="inventory-actions">
                <RouterLink
                    :to="`/app/purchases?create=purchase&product_id=${product.id}`"
                    class="secondary"
                    >Создать закупку с товаром</RouterLink
                >
            </div>
            <section class="panel detail-panel">
                <span class="badge" :class="product.is_active ? 'success' : ''">{{
                    product.is_active ? 'Активен' : 'Неактивен'
                }}</span>
                <h2 class="detail-price">
                    {{ money(product.price_minor, product.currency) }}
                </h2>
                <h3>Описание</h3>
                <p class="preserve-lines">
                    {{ product.description || 'Описание не добавлено.' }}
                </p>
                <dl>
                    <dt>Размещения</dt>
                    <dd>{{ product.listings_count ?? '—' }}</dd>
                    <dt>Создан</dt>
                    <dd>{{ new Date(product.created_at).toLocaleString('ru-RU') }}</dd>
                    <dt>Обновлён</dt>
                    <dd>{{ new Date(product.updated_at).toLocaleString('ru-RU') }}</dd>
                </dl>
                <RouterLink
                    :to="`/app/marketplace-stocks?product_id=${product.id}`"
                    class="text-link"
                    >Остатки на площадках →</RouterLink
                >
                <RouterLink
                    :to="`/app/stocks?product_id=${product.id}`"
                    class="text-link detail-stock-link"
                    >Складские остатки товара →</RouterLink
                >
            </section>
        </template>
    </PageState>
</template>
