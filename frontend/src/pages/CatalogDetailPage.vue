<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { catalogApi, type CatalogDetail, type Source, type Run } from '../api/catalog';
import { syncStatus } from '../api/connections';
import { message } from '../api/client';
import { money } from '../utils/money';
import PageState from '../components/ui/PageState.vue';
import ProductGallery from '../components/catalog/ProductGallery.vue';
import ProductMappingDialog from '../components/catalog/ProductMappingDialog.vue';
const router = useRouter();
const route = useRoute(),
    data = ref<CatalogDetail>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    notice = ref(''),
    mapping = ref<Source>(),
    pending = ref<Record<number, { account: number; run: Run }>>({}),
    refreshing = ref<number>(),
    page = ref(1);
const kind = computed(() => String(route.params.kind || 'local'));
const back = computed(() => {
    const value = String(route.query.return_to || '/app/products');
    return value.startsWith('/app/') && !value.startsWith('//') ? value : '/app/products';
});
let timer: ReturnType<typeof setTimeout>,
    disposed = false,
    version = 0;
function active(run: Run) {
    return ['queued', 'running'].includes(run.status);
}
async function load() {
    const v = ++version;
    loading.value = true;
    error.value = '';
    try {
        const result = await catalogApi.detail(kind.value, String(route.params.id), page.value);
        if (disposed || v !== version) return;
        data.value = result;
        pending.value = {};
        for (const source of result.sources)
            if (source.active_run && active(source.active_run))
                pending.value[source.id] = { account: source.account.id, run: source.active_run };
        schedule();
    } catch (e) {
        if (v === version) error.value = message(e);
    } finally {
        if (v === version) loading.value = false;
    }
}
function schedule() {
    clearTimeout(timer);
    if (Object.keys(pending.value).length && !disposed) timer = setTimeout(poll, 2000);
}
async function poll() {
    const v = version;
    let finished = false;
    try {
        for (const [key, value] of Object.entries(pending.value)) {
            const run = await catalogApi.run(value.account, value.run.id);
            if (disposed || v !== version) return;
            value.run = run;
            if (!active(run)) {
                delete pending.value[Number(key)];
                finished = true;
                if (run.status === 'completed')
                    notice.value = `Вариант #${key} обновлён из источника.`;
                else
                    actionError.value =
                        run.error_message ||
                        'Обновление завершено с ошибками. Откройте историю подключения.';
            }
        }
        if (finished) await load();
    } catch (e) {
        if (!disposed) actionError.value = message(e);
    } finally {
        schedule();
    }
}
async function refresh(source: Source) {
    refreshing.value = source.id;
    actionError.value = '';
    notice.value = '';
    try {
        const run = await catalogApi.refresh(source.id);
        pending.value[source.id] = { account: source.account.id, run };
        notice.value = `Запрошено обновление варианта «${source.variant_title || source.sku || source.external_id}».`;
        schedule();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        refreshing.value = undefined;
    }
}
async function mapped(text: string) {
    mapping.value = undefined;
    notice.value = text;
    await load();
}
async function unmap(source: Source) {
    actionError.value = '';
    try {
        await catalogApi.map(source.id, null);
        await mapped('Сопоставление отменено. Внешние и физические данные сохранены.');
    } catch (e) {
        actionError.value = message(e);
    }
}
async function changePage(delta: number) {
    await router.replace({ query: { ...route.query, sources_page: page.value + delta } });
}
watch(
    () => [route.params.kind, route.params.id, route.query.sources_page],
    () => {
        page.value = Math.max(1, Number(route.query.sources_page) || 1);
        pending.value = {};
        clearTimeout(timer);
        void load();
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    disposed = true;
    version++;
    clearTimeout(timer);
});
</script>
<template>
    <RouterLink :to="back" class="text-link">← К списку товаров</RouterLink>
    <p v-if="notice" class="info-banner" role="status">{{ notice }}</p>
    <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
    <PageState :loading="loading" :error="error" @retry="load"
        ><template v-if="data"
            ><div class="page-heading">
                <div>
                    <p class="eyebrow">
                        {{ kind === 'local' ? 'ЛОКАЛЬНАЯ SKU' : 'ВНЕШНИЙ ТОВАР' }} ·
                        {{ data.entity_id }}
                    </p>
                    <h1>{{ data.title }}</h1>
                    <p>{{ data.sku || 'SKU не указан' }}</p>
                </div>
                <RouterLink
                    v-if="data.local_product"
                    :to="`/app/products/${data.local_product.id}/edit`"
                    class="primary"
                    data-demo-write
                    >Редактировать</RouterLink
                >
            </div>
            <div class="catalog-detail-grid">
                <section class="panel section-card">
                    <ProductGallery :images="data.images" :title="data.title" />
                </section>
                <section class="panel section-card">
                    <h2>Основная информация</h2>
                    <p class="preserve-lines">{{ data.description || 'Описание не добавлено.' }}</p>
                    <template v-if="data.local_product"
                        ><h3>
                            {{
                                money(
                                    data.local_product.base_price_minor,
                                    data.local_product.currency,
                                )
                            }}
                        </h3>
                        <p>
                            Цена MarketHub ·
                            {{ data.local_product.is_active ? 'Активен' : 'Архивный' }}
                        </p>
                        <p>
                            Обновлён:
                            {{ new Date(data.local_product.updated_at).toLocaleString('ru-RU') }}
                        </p>
                        <RouterLink
                            :to="`/app/purchases?create=purchase&product_id=${data.local_product.id}`"
                            class="text-link"
                            data-demo-write
                            >Создать закупку с товаром →</RouterLink
                        ></template
                    >
                    <p v-else>
                        Внешний источник. Локальная SKU создаётся только по вашему явному действию.
                    </p>
                </section>
            </div>
            <section class="panel section-card" data-tour="physical-product-stock">
                <h2>Физические остатки MarketHub</h2>
                <p v-if="!data.physical_stocks.length">
                    {{
                        data.local_product
                            ? 'На физических складах нет учтённого остатка.'
                            : 'Нет физического учёта для несопоставленных вариантов.'
                    }}
                </p>
                <p
                    v-for="s in data.physical_stocks"
                    :key="`${s.product_id ?? data.local_product?.id}:${s.warehouse_id}`"
                >
                    {{ s.sku ? `${s.sku} · ` : '' }}{{ s.warehouse_name }}: {{ s.quantity }} шт.
                </p>
                <RouterLink
                    v-if="data.local_product"
                    :to="`/app/stocks?product_id=${data.local_product.id}`"
                    class="text-link"
                    >Складские остатки товара →</RouterLink
                >
                <p class="fine-print">
                    Внешние снимки ниже учитываются отдельно и не увеличивают физическое наличие.
                </p>
            </section>
            <section class="panel section-card" data-tour="product-sources">
                <div class="section-heading">
                    <div>
                        <h2>Размещения и варианты</h2>
                        <p>
                            {{ data.sources_count }} размещений · обновление выполняется для
                            выбранного варианта
                        </p>
                    </div>
                </div>
                <p v-if="!data.sources.length">Размещений пока нет.</p>
                <article
                    v-for="source in data.sources"
                    :key="source.id"
                    class="source-card"
                    :data-variant="source.id"
                >
                    <div class="section-heading">
                        <div>
                            <h3>
                                {{ source.variant_title || source.title || 'Вариант' }} ·
                                {{ source.sku || 'Без SKU' }}
                            </h3>
                            <RouterLink :to="`/app/marketplaces/${source.account.id}`"
                                >{{ source.account.provider_name }} ·
                                {{ source.account.name }}</RouterLink
                            ><span v-if="source.account.is_demo" class="badge">Демо</span>
                        </div>
                        <span class="badge">{{ source.status }}</span>
                    </div>
                    <p>
                        Barcode: {{ source.barcode || '—' }} ·
                        {{ money(source.price_minor, source.currency) }}
                    </p>
                    <p class="muted">Внешний ID: {{ source.external_id }}</p>
                    <p>
                        Обновлено из источника:
                        {{
                            source.source_synced_at
                                ? new Date(source.source_synced_at).toLocaleString('ru-RU')
                                : 'Не синхронизировано'
                        }}
                    </p>
                    <p v-if="source.product_id">
                        Сопоставлен с
                        <RouterLink :to="`/app/products/${source.product_id}`">{{
                            source.local_product?.sku || `локальной SKU #${source.product_id}`
                        }}</RouterLink>
                    </p>
                    <p v-else>Несопоставленный внешний вариант</p>
                    <div class="inventory-actions" data-tour="source-actions">
                        <RouterLink
                            v-if="kind !== 'external_variant'"
                            :to="{
                                path: `/app/catalog/external_variant/${source.id}`,
                                query: { return_to: route.fullPath },
                            }"
                            class="text-link"
                            >Открыть вариант →</RouterLink
                        ><RouterLink
                            v-if="kind === 'external_variant' && source.marketplace_product_id"
                            :to="`/app/catalog/external_product/${source.marketplace_product_id}`"
                            class="text-link"
                            >Все варианты товара →</RouterLink
                        >
                        <a
                            v-if="source.admin_url"
                            :href="source.admin_url"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="secondary"
                            >Открыть в {{ source.account.provider_name }} Admin</a
                        ><button
                            v-if="source.capabilities.includes('refresh_listing')"
                            class="primary"
                            :disabled="!!pending[source.id] || refreshing === source.id"
                            data-tour="source-refresh"
                            data-demo-write
                            @click="refresh(source)"
                        >
                            {{
                                pending[source.id] ? 'Обновляется…' : 'Обновить из источника'
                            }}</button
                        ><button
                            v-if="source.origin === 'imported'"
                            class="secondary"
                            data-tour="source-mapping"
                            data-demo-write
                            @click="mapping = source"
                        >
                            Сопоставить с MarketHub</button
                        ><button
                            v-if="source.origin === 'imported' && source.product_id"
                            class="text-link"
                            data-demo-write
                            @click="unmap(source)"
                        >
                            Отменить сопоставление</button
                        ><RouterLink
                            v-if="source.capabilities.includes('sync')"
                            :to="`/app/marketplaces/${source.account.id}?tab=history`"
                            class="text-link"
                            >История подключения →</RouterLink
                        >
                    </div>
                    <p v-if="pending[source.id]" role="status">
                        Задание {{ pending[source.id]?.run.id }} ·
                        {{ syncStatus(pending[source.id]?.run.status || 'queued') }} · обработано
                        {{ pending[source.id]?.run.processed }}
                    </p>
                    <div data-tour="source-inventory">
                        <h4>Внешние остатки</h4>
                        <p>
                            {{
                                source.inventory.tracked === false
                                    ? 'Не отслеживаются'
                                    : source.inventory.quantity === null
                                      ? 'Количество неизвестно'
                                      : `${source.inventory.quantity} шт. — последнее известное значение`
                            }}
                            <span v-if="source.inventory.current === false" class="badge"
                                >Устаревший снимок</span
                            >
                        </p>
                        <div class="table-scroll">
                            <table
                                v-if="
                                    data.inventory.some(
                                        (i) => i.marketplace_listing_id === source.id,
                                    )
                                "
                            >
                                <thead>
                                    <tr>
                                        <th>Локация</th>
                                        <th>Количество</th>
                                        <th>Состояние</th>
                                        <th>Время снимка</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <tr
                                        v-for="level in data.inventory.filter(
                                            (i) => i.marketplace_listing_id === source.id,
                                        )"
                                        :key="level.id"
                                    >
                                        <td>
                                            {{ level.location_name
                                            }}<span
                                                v-if="!level.location_active"
                                                class="table-subtitle"
                                                >Локация отключена</span
                                            >
                                        </td>
                                        <td>
                                            {{
                                                !level.tracked
                                                    ? 'Не отслеживается'
                                                    : level.available === null
                                                      ? 'Неизвестно'
                                                      : `${level.available} шт.`
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                level.is_current ? 'Актуален' : 'Устаревший снимок'
                                            }}
                                        </td>
                                        <td>
                                            {{
                                                new Date(level.source_synced_at).toLocaleString(
                                                    'ru-RU',
                                                )
                                            }}
                                        </td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                        <RouterLink
                            :to="`/app/marketplace-stocks?marketplace_account_id=${source.account.id}`"
                            class="text-link"
                            >Все внешние остатки подключения →</RouterLink
                        >
                    </div>
                </article>
                <p v-if="data.inventory_total > data.inventory.length">
                    Показаны первые {{ data.inventory.length }} снимков. Все локации доступны в
                    разделе внешних остатков.
                </p>
                <div v-if="data.sources_last_page > 1" class="form-actions">
                    <button
                        class="secondary"
                        :disabled="data.sources_page <= 1"
                        @click="changePage(-1)"
                    >
                        Назад</button
                    ><span>{{ data.sources_page }} / {{ data.sources_last_page }}</span
                    ><button
                        class="secondary"
                        :disabled="data.sources_page >= data.sources_last_page"
                        @click="changePage(1)"
                    >
                        Далее
                    </button>
                </div>
            </section></template
        ></PageState
    >
    <ProductMappingDialog
        v-if="mapping"
        :key="mapping.id"
        :source="mapping"
        @close="mapping = undefined"
        @saved="mapped"
    />
</template>
<style scoped>
.catalog-detail-grid {
    display: grid;
    grid-template-columns: minmax(240px, 1fr) minmax(260px, 1.4fr);
    gap: 16px;
    margin-bottom: 24px;
}
.source-card {
    padding: 24px 0;
    border-top: 1px solid var(--border);
    overflow-wrap: anywhere;
}
.source-card h3 {
    margin: 0 0 8px;
}
.source-card .inventory-actions {
    flex-wrap: wrap;
    gap: 10px;
}
.source-card h4 {
    margin-top: 20px;
}
@media (max-width: 1000px) {
    .catalog-detail-grid {
        grid-template-columns: 1fr;
    }
    .source-card .section-heading {
        align-items: flex-start;
        gap: 12px;
    }
}
</style>
