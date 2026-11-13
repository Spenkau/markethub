<script setup lang="ts">
import { computed, onMounted, onBeforeUnmount, reactive, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import {
    shopifyApi,
    runNames,
    type ShopifyListing,
    type ShopifyLevel,
    type ShopifySettings,
    type SyncRun,
} from '../api/shopify';
import { ApiError, message } from '../api/client';
import type { MarketplaceAccount, Page } from '../types';
import { money } from '../utils/money';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
import ResourcePicker from '../components/inventory/ResourcePicker.vue';
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
const route = useRoute(),
    router = useRouter();
const id = computed(() => Number(route.params.id));
const creating = computed(() => !id.value);
const account = ref<MarketplaceAccount>();
const summary = computed(() => account.value?.shopify);
const loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    notice = ref(''),
    busy = ref(false);
const tab = computed(() => String(route.query.tab || 'overview'));
const tabs = {
    overview: 'Обзор',
    products: 'Товары',
    inventory: 'Остатки',
    settings: 'Настройки',
    history: 'История',
};
const form = reactive({ name: '', shop_domain: '', client_id: '', client_secret: '' });
const settings = reactive<ShopifySettings>({
    sync_frequency: 'hourly',
    sync_products: true,
    sync_inventory: true,
    status: 'active',
});
const credentialId = ref(''),
    credentialSecret = ref('');
const products = ref<Page<ShopifyListing>>(),
    levels = ref<Page<ShopifyLevel>>(),
    runs = ref<Page<SyncRun>>();
const locations = ref<{ id: number; name: string }[]>([]);
const search = ref(''),
    status = ref(''),
    mapping = ref(''),
    location = ref(''),
    trigger = ref(''),
    dateFrom = ref(''),
    dateTo = ref(''),
    page = ref(1);
const listLoading = ref(false),
    listError = ref('');
const selected = ref<ShopifyListing>(),
    localProduct = ref<number | null>(null),
    runDetail = ref<SyncRun>();
const confirming = ref(false);
const running = computed(() =>
    ['queued', 'running'].includes(summary.value?.latest_run?.status || ''),
);
let poll: ReturnType<typeof setInterval> | undefined,
    debounce: ReturnType<typeof setTimeout> | undefined;
let sequence = 0,
    stopped = false;
const date = (value?: string | null) => (value ? new Date(value).toLocaleString('ru-RU') : '—');
function explain(e: unknown) {
    if (e instanceof ApiError && Object.keys(e.fields).length)
        return Object.values(e.fields).flat().join(' ');
    return message(e);
}
async function refreshAccount(fill = false) {
    const data = await shopifyApi.get(id.value);
    if (stopped) return;
    account.value = data;
    if (fill && data.shopify)
        Object.assign(settings, {
            sync_frequency: data.shopify.sync_frequency,
            sync_products: data.shopify.sync_products,
            sync_inventory: data.shopify.sync_inventory,
            status: data.status,
        });
}
async function load() {
    error.value = '';
    loading.value = true;
    try {
        if (!creating.value) {
            await refreshAccount(true);
            await loadList();
        }
    } catch (e) {
        error.value = explain(e);
    } finally {
        loading.value = false;
    }
}
async function loadList() {
    const current = ++sequence;
    if (creating.value) return;
    listLoading.value = true;
    listError.value = '';
    const params = {
        page: page.value,
        search: search.value || undefined,
        status: status.value || undefined,
        mapping: mapping.value || undefined,
        location_id: location.value || undefined,
        product_id: route.query.product_id ? String(route.query.product_id) : undefined,
        trigger: trigger.value || undefined,
        date_from: dateFrom.value || undefined,
        date_to: dateTo.value || undefined,
    };
    try {
        if (tab.value === 'products') {
            const data = await shopifyApi.products(id.value, params);
            if (current === sequence) products.value = data;
        }
        if (tab.value === 'inventory') {
            const [data, locs] = await Promise.all([
                shopifyApi.inventory(id.value, params),
                shopifyApi.locations(id.value),
            ]);
            if (current === sequence) {
                levels.value = data;
                locations.value = locs;
            }
        }
        if (tab.value === 'history') {
            const data = await shopifyApi.runs(id.value, params);
            if (current === sequence) runs.value = data;
        }
    } catch (e) {
        if (current === sequence) listError.value = explain(e);
    } finally {
        if (current === sequence) listLoading.value = false;
    }
}
async function perform(fn: () => Promise<void>) {
    if (busy.value) return;
    busy.value = true;
    actionError.value = '';
    notice.value = '';
    try {
        await fn();
    } catch (e) {
        actionError.value = explain(e);
    } finally {
        busy.value = false;
    }
}
async function create() {
    await perform(async () => {
        const data = await shopifyApi.create(form);
        form.client_id = '';
        form.client_secret = '';
        await router.push(`/app/marketplaces/shopify/${data.id}`);
    });
}
async function check() {
    await perform(async () => {
        const result = await shopifyApi.check(id.value);
        notice.value = result.missing_scopes.length
            ? `Не хватает прав: ${result.missing_scopes.join(', ')}`
            : `Доступ к магазину «${result.shop_name}» проверен.`;
        await refreshAccount();
    });
}
async function sync() {
    if (!summary.value?.initial_import_at && !confirming.value) {
        confirming.value = true;
        return;
    }
    await perform(async () => {
        const run = await shopifyApi.sync(id.value, confirming.value);
        confirming.value = false;
        notice.value = `Синхронизация поставлена в очередь. Задание ${run.id}.`;
        await refreshAccount();
    });
}
async function saveSettings() {
    await perform(async () => {
        account.value = await shopifyApi.settings(id.value, {
            ...settings,
            ...(credentialId.value ? { client_id: credentialId.value } : {}),
            ...(credentialSecret.value ? { client_secret: credentialSecret.value } : {}),
        });
        credentialId.value = '';
        credentialSecret.value = '';
        notice.value = 'Настройки сохранены. Расписание обновлено.';
    });
}
async function disableAuto() {
    await perform(async () => {
        account.value = await shopifyApi.settings(id.value, {
            ...settings,
            sync_frequency: 'manual',
        });
        settings.sync_frequency = 'manual';
        notice.value = 'Автоматическая синхронизация отключена.';
    });
}
async function saveMapping() {
    if (!selected.value) return;
    const listing = selected.value;
    await perform(async () => {
        await shopifyApi.map(id.value, listing.id, localProduct.value);
        selected.value = undefined;
        notice.value = 'Сопоставление сохранено. Поля локального товара не изменены.';
        await refreshAccount();
        await loadList();
    });
}
async function showRun(run: SyncRun) {
    await perform(async () => {
        runDetail.value = await shopifyApi.run(id.value, run.id);
    });
}
function duration(run: SyncRun) {
    if (!run.started_at || !run.finished_at) return '—';
    return `${Math.round((Date.parse(run.finished_at) - Date.parse(run.started_at)) / 1000)} сек.`;
}
watch(tab, () => {
    search.value = '';
    status.value = '';
    mapping.value = '';
    location.value = '';
    trigger.value = '';
    dateFrom.value = '';
    dateTo.value = '';
    page.value = 1;
    void loadList();
});
watch([search, status, mapping, location, trigger, dateFrom, dateTo], () => {
    page.value = 1;
    clearTimeout(debounce);
    debounce = setTimeout(loadList, 300);
});
async function changePage(value: number) {
    page.value = value;
    await loadList();
}
onMounted(() => {
    void load();
    poll = setInterval(async () => {
        if (!running.value || busy.value || stopped) return;
        try {
            const wasRunning = running.value;
            await refreshAccount();
            if (wasRunning && !running.value) await loadList();
        } catch {
            /* An explicit reload remains available if polling cannot connect. */
        }
    }, 4000);
});
onBeforeUnmount(() => {
    stopped = true;
    sequence++;
    clearInterval(poll);
    clearTimeout(debounce);
    form.client_id = '';
    form.client_secret = '';
    credentialId.value = '';
    credentialSecret.value = '';
});
</script>

<template>
    <RouterLink to="/app/marketplaces" class="text-link">← Все подключения</RouterLink>
    <div class="page-heading" data-tour="shopify-heading">
        <div>
            <p class="eyebrow">SHOPIFY → MARKETHUB</p>
            <h1>{{ creating ? 'Подключить Shopify' : account?.name || 'Shopify' }}</h1>
            <p>{{ summary?.domain || 'Импорт каталога и внешних остатков' }}</p>
        </div>
    </div>
    <p class="info-banner">
        Данные импортируются только из Shopify. Изменения MarketHub обратно не отправляются. Внешние
        остатки не увеличивают физические запасы.
    </p>
    <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
    <p v-if="notice" class="next-action-banner" role="status">{{ notice }}</p>
    <PageState :loading="loading" :error="error" @retry="load">
        <form v-if="creating" class="panel section-card" @submit.prevent="create">
            <h2>Реквизиты установленного приложения</h2>
            <label>Название магазина<input v-model="form.name" required maxlength="255" /></label>
            <label
                >Домен магазина<input
                    v-model="form.shop_domain"
                    placeholder="store.myshopify.com"
                    required
                    maxlength="255"
            /></label>
            <label>Client ID<input v-model="form.client_id" required autocomplete="off" /></label>
            <label
                >Client Secret<input
                    v-model="form.client_secret"
                    type="password"
                    required
                    autocomplete="new-password"
            /></label>
            <p class="fine-print">
                По умолчанию: каждый час, товары и остатки включены. Первый импорт требует
                отдельного подтверждения. Реквизиты сохраняются зашифрованными.
            </p>
            <button class="primary" :disabled="busy">
                {{ busy ? 'Подключаем…' : 'Сохранить подключение' }}
            </button>
        </form>
        <template v-else-if="account && summary">
            <TourLauncher tour-id="shopify" />
            <nav class="inventory-actions" aria-label="Разделы Shopify" data-tour="shopify-tabs">
                <RouterLink
                    v-for="(label, key) in tabs"
                    :key="key"
                    :to="{ query: { tab: key } }"
                    :class="tab === key ? 'primary' : 'secondary'"
                    >{{ label }}</RouterLink
                >
            </nav>
            <div v-if="running" class="info-banner" role="status">
                <span class="spinner"></span> {{ runNames[summary.latest_run!.status] }} ·
                обработано {{ summary.latest_run?.processed }} записей. Процент заранее неизвестен.
                Для выполнения нужен queue worker.
            </div>
            <template v-if="tab === 'overview'">
                <section class="panel section-card" data-tour="shopify-overview">
                    <h2>{{ summary.domain }}</h2>
                    <dl>
                        <dt>Подключение</dt>
                        <dd>{{ account.status === 'active' ? 'Включено' : 'Отключено' }}</dd>
                        <dt>Доступ API</dt>
                        <dd>
                            {{
                                summary.api_status === 'connected'
                                    ? 'Проверен'
                                    : summary.api_status === 'unchecked'
                                      ? 'Не проверен'
                                      : 'Требует проверки'
                            }}
                        </dd>
                        <dt>Последняя успешная синхронизация</dt>
                        <dd>{{ date(summary.last_synced_at) }}</dd>
                        <dt>Следующий запуск</dt>
                        <dd>{{ date(summary.next_sync_at) }}</dd>
                        <dt>Товаров Shopify</dt>
                        <dd>{{ summary.products_count }}</dd>
                        <dt>Вариантов</dt>
                        <dd>{{ summary.variants_count }}</dd>
                        <dt>Не сопоставлено вариантов</dt>
                        <dd>{{ summary.unmapped_count }}</dd>
                        <dt>Последнее задание</dt>
                        <dd>
                            {{
                                summary.latest_run
                                    ? runNames[summary.latest_run.status]
                                    : 'Запусков нет'
                            }}
                        </dd>
                    </dl>
                    <p v-if="summary.latest_run?.error_message" class="alert">
                        {{ summary.latest_run.error_message }}
                    </p>
                    <div class="inventory-actions">
                        <button class="secondary" :disabled="busy || running" @click="check">
                            Проверить подключение</button
                        ><button
                            class="primary"
                            data-tour="shopify-sync"
                            :disabled="
                                busy ||
                                running ||
                                account.status !== 'active' ||
                                (!summary.sync_products && !summary.sync_inventory)
                            "
                            @click="sync"
                        >
                            {{
                                summary.initial_import_at
                                    ? 'Синхронизировать сейчас'
                                    : 'Выполнить первый импорт'
                            }}
                        </button>
                    </div>
                    <div class="inventory-actions">
                        <RouterLink :to="{ query: { tab: 'products' } }" class="text-link"
                            >Просмотреть товары →</RouterLink
                        ><RouterLink
                            :to="`/app/products?marketplace=shopify&marketplace_account_id=${id}`"
                            class="text-link"
                            >Открыть каталог MarketHub →</RouterLink
                        ><button
                            class="text-link"
                            :disabled="busy || running || summary.sync_frequency === 'manual'"
                            @click="disableAuto"
                        >
                            Отключить автоматическую синхронизацию
                        </button>
                    </div>
                    <p v-if="!summary.initial_import_at" class="fine-print">
                        После первого успешного импорта включится выбранное расписание. До
                        подтверждения данные не импортируются.
                    </p>
                </section>
            </template>
            <form
                v-else-if="tab === 'settings'"
                class="panel section-card shopify-settings"
                data-tour="shopify-settings"
                @submit.prevent="saveSettings"
            >
                <h2>Настройки синхронизации</h2>
                <label
                    >Периодичность<select v-model="settings.sync_frequency">
                        <option value="manual">Только вручную</option>
                        <option value="hourly">Каждый час</option>
                        <option value="daily">Раз в сутки</option>
                    </select></label
                >
                <label class="checkbox-label"
                    ><input v-model="settings.sync_products" type="checkbox" />Синхронизировать
                    товары</label
                ><label class="checkbox-label"
                    ><input v-model="settings.sync_inventory" type="checkbox" />Синхронизировать
                    остатки</label
                >
                <label
                    >Подключение<select v-model="settings.status">
                        <option value="active">Включено</option>
                        <option value="disabled">Отключено</option>
                    </select></label
                >
                <p>Следующий запуск: {{ date(summary.next_sync_at) }}</p>
                <p class="fine-print">
                    При выключенном компьютере, Scheduler или queue worker обновления не
                    выполняются. Отключение обоих типов данных останавливает расписание. Поля уже
                    созданных локальных товаров при импорте сохраняются.
                </p>
                <details>
                    <summary>Заменить реквизиты приложения</summary>
                    <label>Новый Client ID<input v-model="credentialId" autocomplete="off" /></label
                    ><label
                        >Новый Client Secret<input
                            v-model="credentialSecret"
                            type="password"
                            autocomplete="new-password"
                    /></label>
                    <p class="fine-print">
                        Оставьте пустыми, чтобы сохранить текущие значения. Секреты не возвращаются
                        браузеру.
                    </p>
                </details>
                <button class="primary" :disabled="busy || running">Сохранить настройки</button>
            </form>
            <template v-else>
                <section class="panel filters-panel">
                    <div class="toolbar">
                        <input
                            v-if="tab !== 'history'"
                            v-model="search"
                            placeholder="Название или SKU"
                            aria-label="Поиск Shopify"
                            maxlength="100"
                        />
                        <template v-if="tab === 'products'"
                            ><select v-model="status" aria-label="Статус Shopify">
                                <option value="">Все статусы</option>
                                <option value="active">Активен</option>
                                <option value="draft">Черновик</option>
                                <option value="archived">Архив</option>
                                <option value="deleted">Удалён в Shopify</option></select
                            ><select v-model="mapping" aria-label="Сопоставление">
                                <option value="">Все варианты</option>
                                <option value="unmapped">Не сопоставлены</option>
                                <option value="mapped">Сопоставлены</option>
                            </select></template
                        >
                        <select
                            v-if="tab === 'inventory'"
                            v-model="location"
                            aria-label="Локация Shopify"
                        >
                            <option value="">Все локации</option>
                            <option v-for="loc in locations" :key="loc.id" :value="loc.id">
                                {{ loc.name }}
                            </option>
                        </select>
                        <template v-if="tab === 'history'"
                            ><select v-model="status" aria-label="Статус запуска">
                                <option value="">Все статусы</option>
                                <option v-for="(label, key) in runNames" :key="key" :value="key">
                                    {{ label }}
                                </option></select
                            ><select v-model="trigger" aria-label="Тип запуска">
                                <option value="">Все запуски</option>
                                <option value="manual">Ручные</option>
                                <option value="automatic">Автоматические</option></select
                            ><label>С даты<input v-model="dateFrom" type="date" /></label
                            ><label>По дату<input v-model="dateTo" type="date" /></label
                        ></template>
                        <button class="secondary" @click="loadList">Обновить</button>
                    </div>
                </section>
                <p v-if="tab === 'inventory'" class="info-banner">
                    Внешние данные Shopify, только для чтения. «—» означает неизвестное количество
                    или отключённый учёт. Устаревшие снимки не являются текущими остатками.
                </p>
                <PageState
                    :loading="listLoading"
                    :error="listError"
                    :empty="
                        tab === 'products'
                            ? !products?.meta.total
                            : tab === 'inventory'
                              ? !levels?.meta.total
                              : !runs?.meta.total
                    "
                    @retry="loadList"
                >
                    <section
                        class="panel table-scroll"
                        :data-tour="
                            tab === 'products'
                                ? 'shopify-products'
                                : tab === 'inventory'
                                  ? 'shopify-inventory'
                                  : 'shopify-history'
                        "
                    >
                        <table v-if="tab === 'products'">
                            <thead>
                                <tr>
                                    <th>Товар / вариант</th>
                                    <th>SKU</th>
                                    <th>Цена Shopify</th>
                                    <th>Статус</th>
                                    <th>Каталог MarketHub</th>
                                    <th>Обновление</th>
                                    <th>Действия</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in products?.data" :key="item.id">
                                    <td>
                                        <img
                                            v-if="item.source_data.product.images[0]"
                                            :src="item.source_data.product.images[0].url"
                                            alt=""
                                            class="shopify-thumbnail"
                                            width="96"
                                            height="96"
                                            loading="lazy"
                                        /><RouterLink
                                            :to="`/app/marketplaces/shopify/${id}/products/${item.id}`"
                                            class="product-name"
                                            >{{ item.source_data.product.title }}</RouterLink
                                        >
                                        <p class="fine-print">
                                            {{ item.source_data.variant.title }}
                                        </p>
                                    </td>
                                    <td>{{ item.external_sku || 'Без SKU' }}</td>
                                    <td>
                                        {{ money(item.price_minor, item.currency) }}
                                        <p
                                            v-if="item.source_data.variant.compareAtPrice"
                                            class="fine-print"
                                        >
                                            До скидки: {{ item.source_data.variant.compareAtPrice }}
                                            {{ item.currency }}
                                        </p>
                                    </td>
                                    <td>{{ item.source_status }}</td>
                                    <td>
                                        <RouterLink
                                            v-if="item.product_id"
                                            :to="`/app/products/${item.product_id}`"
                                            >{{ item.local_name }} ·
                                            {{ item.local_sku }}</RouterLink
                                        ><span v-else class="badge warning">Не сопоставлен</span>
                                    </td>
                                    <td>{{ date(item.source_synced_at) }}</td>
                                    <td>
                                        <button
                                            class="text-link"
                                            data-tour="shopify-mapping"
                                            @click="
                                                selected = item;
                                                localProduct = item.product_id;
                                            "
                                        >
                                            Сопоставить с каталогом</button
                                        ><a
                                            :href="item.admin_url"
                                            target="_blank"
                                            rel="noopener noreferrer"
                                            class="text-link"
                                            >Открыть в Shopify ↗</a
                                        >
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <table v-else-if="tab === 'inventory'">
                            <thead>
                                <tr>
                                    <th>Товар / SKU</th>
                                    <th>Локация Shopify</th>
                                    <th>Доступно</th>
                                    <th>Физически в Shopify</th>
                                    <th>Зарезервировано</th>
                                    <th>Ожидается</th>
                                    <th>Обновление / статус</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="item in levels?.data" :key="item.id">
                                    <td>
                                        <RouterLink
                                            v-if="item.product_id"
                                            :to="`/app/products/${item.product_id}`"
                                            >{{ item.title }}</RouterLink
                                        ><span v-else>{{ item.title }}</span>
                                        <p>{{ item.external_sku || 'Без SKU' }}</p>
                                    </td>
                                    <td>{{ item.location_name }}</td>
                                    <td>{{ item.available ?? '—' }}</td>
                                    <td>{{ item.on_hand ?? '—' }}</td>
                                    <td>{{ item.committed ?? '—' }}</td>
                                    <td>{{ item.incoming ?? '—' }}</td>
                                    <td>
                                        {{ date(item.source_synced_at) }}
                                        <p v-if="!item.is_current" class="badge warning">
                                            Устаревший снимок
                                        </p>
                                        <p v-if="!item.tracked" class="fine-print">
                                            Учёт отключён в Shopify
                                        </p>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <table v-else>
                            <thead>
                                <tr>
                                    <th>Дата / тип</th>
                                    <th>Статус</th>
                                    <th>Обработано</th>
                                    <th>Создано / обновлено</th>
                                    <th>Пропущено / ошибок</th>
                                    <th>Длительность</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="run in runs?.data" :key="run.id">
                                    <td>
                                        {{ date(run.created_at) }}
                                        <p>
                                            {{
                                                run.trigger === 'manual'
                                                    ? 'Ручной'
                                                    : 'Автоматический'
                                            }}
                                        </p>
                                    </td>
                                    <td>{{ runNames[run.status] }}</td>
                                    <td>{{ run.processed }}</td>
                                    <td>{{ run.created }} / {{ run.updated }}</td>
                                    <td>{{ run.skipped }} / {{ run.errors_count }}</td>
                                    <td>{{ duration(run) }}</td>
                                    <td>
                                        <button class="text-link" @click="showRun(run)">
                                            Детали
                                        </button>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </section>
                </PageState>
                <PaginationBar
                    v-if="tab === 'products' && products"
                    :meta="products.meta"
                    :disabled="listLoading"
                    @change="changePage"
                /><PaginationBar
                    v-if="tab === 'inventory' && levels"
                    :meta="levels.meta"
                    :disabled="listLoading"
                    @change="changePage"
                /><PaginationBar
                    v-if="tab === 'history' && runs"
                    :meta="runs.meta"
                    :disabled="listLoading"
                    @change="changePage"
                />
            </template>
        </template>
    </PageState>
    <div v-if="confirming" class="modal-backdrop" @keydown.esc="!busy && (confirming = false)">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="import-title">
            <h2 id="import-title">Первоначальный импорт Shopify</h2>
            <p>
                Импортировать {{ summary?.sync_products ? 'товары' : '' }}
                {{ summary?.sync_inventory ? 'и внешние остатки' : '' }} из {{ summary?.domain }}?
                Внешние варианты будут доступны для сопоставления. Локальные карточки создаются
                отдельным действием пользователя. Физические запасы не изменятся.
            </p>
            <p>После успеха начнёт действовать расписание: {{ summary?.sync_frequency }}.</p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" @click="confirming = false">
                    Отмена</button
                ><button class="primary" :disabled="busy" @click="sync">Подтвердить импорт</button>
            </div>
        </section>
    </div>
    <div v-if="selected" class="modal-backdrop" @keydown.esc="!busy && (selected = undefined)">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="mapping-title">
            <h2 id="mapping-title">Сопоставить вариант Shopify</h2>
            <p>
                {{ selected.source_data.product.title }} · {{ selected.external_sku || 'Без SKU' }}
            </p>
            <ResourcePicker
                v-model="localProduct"
                expanded
                kind="products"
                label="Товар MarketHub"
                :selected-option="
                    selected.product_id
                        ? {
                              id: selected.product_id,
                              label: `${selected.local_name} · ${selected.local_sku}`,
                          }
                        : undefined
                "
            />
            <p class="fine-print">
                Пустой выбор снимает сопоставление. Поля товара, физические остатки и данные Shopify
                не меняются.
            </p>
            <p v-if="actionError" class="alert">{{ actionError }}</p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" @click="selected = undefined">
                    Отмена</button
                ><button class="primary" :disabled="busy" @click="saveMapping">
                    Сохранить сопоставление
                </button>
            </div>
        </section>
    </div>
    <div v-if="runDetail" class="modal-backdrop" @keydown.esc="runDetail = undefined">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="run-title">
            <h2 id="run-title">Запуск {{ runDetail.id }}</h2>
            <p>
                {{ runNames[runDetail.status] }} ·
                {{ runDetail.full_reconcile ? 'Полная сверка' : 'Обновление изменений' }}
            </p>
            <p>
                Товары: {{ runDetail.sync_products ? 'да' : 'нет' }}. Остатки:
                {{ runDetail.sync_inventory ? 'да' : 'нет' }}.
            </p>
            <p>
                Начало: {{ date(runDetail.started_at) }}. Завершение:
                {{ date(runDetail.finished_at) }}.
            </p>
            <p>
                Товаров {{ runDetail.products_processed }}, вариантов
                {{ runDetail.variants_processed }}, остатков {{ runDetail.inventory_processed }}.
                Создано {{ runDetail.created }}, обновлено {{ runDetail.updated }}, пропущено
                {{ runDetail.skipped }}, ошибок {{ runDetail.errors_count }}.
            </p>
            <p v-if="runDetail.error_message" class="alert">{{ runDetail.error_message }}</p>
            <ul>
                <li v-for="(detail, index) in runDetail.details" :key="index">
                    {{ detail.reference }}: {{ detail.message }}
                </li>
            </ul>
            <details v-if="runDetail.attempt_errors?.length">
                <summary>Диагностика предыдущих попыток</summary>
                <p v-for="(event, index) in runDetail.attempt_errors" :key="index">
                    Попытка {{ event.attempt }}: {{ event.message }}
                </p>
            </details>
            <button class="secondary" @click="runDetail = undefined">Закрыть</button>
        </section>
    </div>
</template>

<style scoped>
.shopify-settings {
    display: grid;
    gap: 20px;
    max-width: 760px;
}
.shopify-settings > label:not(.checkbox-label) {
    display: grid;
    gap: 8px;
}
.shopify-settings details {
    padding: 16px;
    border: 1px solid var(--border);
    border-radius: 10px;
}
.shopify-settings details label {
    display: grid;
    gap: 8px;
    margin-top: 16px;
}
.shopify-settings > button {
    justify-self: start;
}
.shopify-thumbnail {
    width: 96px;
    height: 96px;
    object-fit: contain;
    border-radius: 10px;
    background: #f5f7fa;
    display: block;
    margin-bottom: 10px;
}
</style>
