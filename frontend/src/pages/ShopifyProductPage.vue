<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute } from 'vue-router';
import {
    shopifyApi,
    runNames,
    type ShopifyListing,
    type ShopifyLevel,
    type SyncRun,
} from '../api/shopify';
import { message } from '../api/client';
import { money } from '../utils/money';
import PageState from '../components/ui/PageState.vue';
import ResourcePicker from '../components/inventory/ResourcePicker.vue';
const route = useRoute();
const accountId = computed(() => Number(route.params.id));
const listingId = computed(() => Number(route.params.listing));
const item = ref<ShopifyListing & { levels: ShopifyLevel[] }>();
const loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    notice = ref(''),
    busy = ref(false);
const run = ref<SyncRun>();
const mapping = ref(false),
    localProduct = ref<number | null>(null),
    imageIndex = ref(0);
const running = computed(() => !!run.value && ['queued', 'running'].includes(run.value.status));
const images = computed(() => item.value?.source_data.product.images ?? []);
const date = (value?: string | null) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
let timer: ReturnType<typeof setTimeout> | undefined;
let stopped = false,
    sequence = 0;
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const response = await shopifyApi.product(accountId.value, listingId.value);
        if (!stopped && current === sequence) {
            item.value = response;
            if (response.active_run && !run.value) {
                run.value = response.active_run;
                void poll();
            }
        }
    } catch (e) {
        if (!stopped && current === sequence) error.value = message(e);
    } finally {
        if (!stopped && current === sequence) loading.value = false;
    }
}
async function poll() {
    if (stopped || !run.value) return;
    try {
        run.value = await shopifyApi.run(accountId.value, run.value.id);
        if (!running.value) {
            await load();
            notice.value =
                run.value.status === 'completed' ? 'Свежие данные Shopify получены.' : '';
            return;
        }
    } catch (e) {
        actionError.value = message(e);
    }
    if (!stopped) timer = setTimeout(poll, 2000);
}
async function refresh() {
    busy.value = true;
    actionError.value = '';
    notice.value = '';
    try {
        run.value = await shopifyApi.refreshProduct(accountId.value, listingId.value);
        void poll();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
async function saveMapping() {
    busy.value = true;
    actionError.value = '';
    try {
        await shopifyApi.map(accountId.value, listingId.value, localProduct.value);
        mapping.value = false;
        await load();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
watch(
    () => route.fullPath,
    () => {
        clearTimeout(timer);
        run.value = undefined;
        imageIndex.value = 0;
        void load();
    },
);
onMounted(load);
onBeforeUnmount(() => {
    stopped = true;
    sequence++;
    clearTimeout(timer);
});
</script>
<template>
    <RouterLink :to="`/app/marketplaces/shopify/${accountId}?tab=products`" class="text-link"
        >← Товары магазина</RouterLink
    >
    <PageState :loading="loading" :error="error" @retry="load">
        <template v-if="item">
            <div class="page-heading">
                <div>
                    <p class="eyebrow">ТОВАР SHOPIFY · {{ item.source_status }}</p>
                    <h1>{{ item.source_data.product.title }}</h1>
                    <p>
                        {{ item.source_data.variant.title }} · {{ item.external_sku || 'Без SKU' }}
                    </p>
                </div>
                <button class="primary" :disabled="busy || running" @click="refresh">
                    {{ running ? 'Получаем данные…' : 'Обновить из Shopify' }}
                </button>
            </div>
            <p class="info-banner">
                После редактирования товара в Shopify сохраните изменения там, затем нажмите
                «Обновить из Shopify». Обновляются данные этого варианта и его внешние остатки.
                Локальные поля каталога сохраняются.
            </p>
            <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
            <p v-if="notice" class="next-action-banner" role="status">{{ notice }}</p>
            <p v-if="run" role="status">
                {{ runNames[run.status] }}
                <span v-if="run.error_message">· {{ run.error_message }}</span>
            </p>
            <div class="shopify-product-layout">
                <section class="panel section-card gallery" aria-label="Фотографии товара">
                    <img
                        v-if="images[imageIndex]"
                        class="main-image"
                        :src="images[imageIndex]!.url"
                        :alt="images[imageIndex]!.altText || item.source_data.product.title"
                    />
                    <p v-else>Нет изображения</p>
                    <div class="image-strip">
                        <button
                            v-for="(image, index) in images"
                            :key="image.url"
                            class="image-choice"
                            :aria-label="`Изображение ${index + 1}`"
                            :aria-pressed="imageIndex === index"
                            @click="imageIndex = index"
                        >
                            <img :src="image.url" alt="" loading="lazy" />
                        </button>
                    </div>
                </section>
                <section class="panel section-card details">
                    <h2>Данные источника</h2>
                    <dl class="details-list">
                        <dt>Цена Shopify</dt>
                        <dd>{{ money(item.price_minor, item.currency) }}</dd>
                        <dt>До скидки</dt>
                        <dd>{{ item.source_data.variant.compareAtPrice ?? '—' }}</dd>
                        <dt>SKU</dt>
                        <dd>{{ item.external_sku || 'Без SKU' }}</dd>
                        <dt>Штрихкод</dt>
                        <dd>{{ item.source_data.variant.barcode || '—' }}</dd>
                        <dt>Поставщик</dt>
                        <dd>{{ item.source_data.product.vendor || '—' }}</dd>
                        <dt>Получено из Shopify</dt>
                        <dd>{{ date(item.source_synced_at) }}</dd>
                        <dt>Внешний остаток</dt>
                        <dd>{{ item.stock_quantity ?? 'Неизвестно' }}</dd>
                    </dl>
                    <a
                        :href="item.admin_url"
                        target="_blank"
                        rel="noopener noreferrer"
                        class="secondary"
                        >Редактировать в Shopify ↗</a
                    >
                    <h2>Каталог MarketHub</h2>
                    <RouterLink v-if="item.product_id" :to="`/app/products/${item.product_id}`"
                        >{{ item.local_name }} · {{ item.local_sku }}</RouterLink
                    >
                    <p v-else>Вариант не сопоставлен с локальным каталогом.</p>
                    <button
                        class="secondary"
                        @click="
                            localProduct = item.product_id;
                            mapping = true;
                        "
                    >
                        Сопоставить с каталогом
                    </button>
                </section>
            </div>
            <section class="panel table-scroll">
                <table>
                    <thead>
                        <tr>
                            <th>Локация Shopify</th>
                            <th>Доступно</th>
                            <th>На руках</th>
                            <th>Зарезервировано</th>
                            <th>В пути</th>
                            <th>Получено</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr v-for="level in item.levels" :key="level.id">
                            <td>
                                {{ level.location_name }}
                                <p v-if="!level.is_current">Устаревший снимок</p>
                            </td>
                            <td>{{ level.available ?? '—' }}</td>
                            <td>{{ level.on_hand ?? '—' }}</td>
                            <td>{{ level.committed ?? '—' }}</td>
                            <td>{{ level.incoming ?? '—' }}</td>
                            <td>{{ date(level.source_synced_at) }}</td>
                        </tr>
                        <tr v-if="!item.levels.length">
                            <td colspan="6">Остатков по локациям нет</td>
                        </tr>
                    </tbody>
                </table>
            </section>
        </template>
    </PageState>
    <div v-if="mapping" class="modal-backdrop" @keydown.esc="!busy && (mapping = false)">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="mapping-title">
            <h2 id="mapping-title">Сопоставить с каталогом MarketHub</h2>
            <ResourcePicker
                v-model="localProduct"
                kind="products"
                label="Товар MarketHub"
                expanded
                :selected-option="
                    item?.product_id
                        ? { id: item.product_id, label: `${item.local_name} · ${item.local_sku}` }
                        : undefined
                "
            />
            <p v-if="actionError" class="alert">{{ actionError }}</p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" @click="mapping = false">Отмена</button
                ><button class="primary" :disabled="busy" @click="saveMapping">
                    Сохранить сопоставление
                </button>
            </div>
        </section>
    </div>
</template>
<style scoped>
.shopify-product-layout {
    display: grid;
    grid-template-columns: minmax(260px, 1fr) minmax(280px, 1fr);
    gap: 24px;
    margin-bottom: 24px;
}
.main-image {
    width: 100%;
    height: 400px;
    object-fit: contain;
    background: #f5f7fa;
    border-radius: 12px;
}
.image-strip {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-top: 16px;
}
.image-choice {
    border: 2px solid transparent;
    border-radius: 8px;
    padding: 3px;
    background: #f5f7fa;
}
.image-choice[aria-pressed='true'] {
    border-color: var(--primary);
}
.image-choice img {
    width: 76px;
    height: 76px;
    object-fit: contain;
}
.details {
    display: flex;
    flex-direction: column;
    align-items: flex-start;
    gap: 20px;
}
@media (max-width: 720px) {
    .shopify-product-layout {
        grid-template-columns: 1fr;
    }
    .main-image {
        height: 300px;
    }
}
</style>
