<script setup lang="ts">
import { ref, computed, watch, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { connectionsApi, syncStatus, apiStatus, type Connection } from '../api/connections';
import type { Run } from '../api/catalog';
import { message } from '../api/client';
import PageState from '../components/ui/PageState.vue';
import MarketplaceProductList from '../components/catalog/MarketplaceProductList.vue';
import MarketplaceConnectionForm from '../components/marketplaces/MarketplaceConnectionForm.vue';
import MarketplaceSyncHistory from '../components/marketplaces/MarketplaceSyncHistory.vue';
import MarketplaceStocksPage from './MarketplaceStocksPage.vue';
const route = useRoute(),
    router = useRouter(),
    account = ref<Connection>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    notice = ref(''),
    busy = ref(false),
    confirm = ref(false),
    edit = ref(false),
    run = ref<Run | null>(null),
    revision = ref(0);
const id = computed(() => Number(route.params.id)),
    tab = computed(() => {
        const value = String(route.query.tab || 'overview');
        const allowed = [
            'overview',
            'products',
            'settings',
            ...(caps.value.includes('inventory') || account.value?.is_demo ? ['inventory'] : []),
            ...(caps.value.includes('sync') || caps.value.includes('history') ? ['history'] : []),
        ];
        return allowed.includes(value) ? value : 'overview';
    }),
    caps = computed(() => account.value?.capabilities || []),
    frequency = ref('hourly'),
    products = ref(true),
    inventory = ref(true);
let timer: ReturnType<typeof setTimeout>,
    disposed = false,
    seq = 0;
function settings() {
    if (!account.value) return;
    frequency.value = account.value.integration.sync_frequency;
    products.value = account.value.integration.sync_products;
    inventory.value = account.value.integration.sync_inventory;
}
async function load() {
    const v = ++seq;
    loading.value = true;
    error.value = '';
    try {
        const data = await connectionsApi.get(id.value);
        if (disposed || v !== seq) return;
        account.value = data;
        settings();
        run.value =
            data.integration.latest_run &&
            ['queued', 'running'].includes(data.integration.latest_run.status)
                ? data.integration.latest_run
                : null;
        schedule();
    } catch (e) {
        if (v === seq) error.value = message(e);
    } finally {
        if (v === seq) loading.value = false;
    }
}
function schedule() {
    clearTimeout(timer);
    if (run.value && ['queued', 'running'].includes(run.value.status) && !disposed)
        timer = setTimeout(poll, 2000);
}
async function poll() {
    const accountId = id.value;
    try {
        const status = await connectionsApi.status(id.value);
        if (disposed || accountId !== id.value) return;
        run.value = status.active_run;
        if (!run.value) {
            notice.value =
                status.latest_run?.status === 'completed'
                    ? 'Синхронизация завершена.'
                    : 'Запуск завершён. Проверьте результат и ошибки в истории.';
            revision.value++;
            await load();
        }
    } catch (e) {
        actionError.value = message(e);
    } finally {
        schedule();
    }
}
async function check() {
    busy.value = true;
    actionError.value = '';
    try {
        const result = await connectionsApi.check(id.value);
        await load();
        notice.value = result.missing_scopes?.length
            ? `Недостаточно прав: ${result.missing_scopes.join(', ')}`
            : 'API проверен.';
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
async function sync() {
    busy.value = true;
    actionError.value = '';
    try {
        run.value = await connectionsApi.sync(id.value, confirm.value);
        confirm.value = false;
        schedule();
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
async function saveSettings() {
    notice.value = '';
    busy.value = true;
    actionError.value = '';
    try {
        account.value = await connectionsApi.save(
            {
                sync_frequency: frequency.value,
                sync_products: products.value,
                sync_inventory: inventory.value,
            },
            id.value,
        );
        settings();
        notice.value = 'Настройки синхронизации сохранены.';
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = false;
    }
}
async function saved(value: Connection) {
    account.value = value;
    edit.value = false;
    settings();
    notice.value = 'Подключение сохранено.';
}
function selectTab(value: string) {
    void router.replace({ query: { tab: value } });
}
watch(
    id,
    () => {
        clearTimeout(timer);
        run.value = null;
        void load();
    },
    { immediate: true },
);
onBeforeUnmount(() => {
    disposed = true;
    seq++;
    clearTimeout(timer);
});
</script>
<template>
    <RouterLink to="/app/marketplaces" class="text-link">← Все подключения</RouterLink>
    <p v-if="notice" role="status" class="info-banner">{{ notice }}</p>
    <p v-if="actionError" class="alert" role="alert">{{ actionError }}</p>
    <PageState :loading="loading" :error="error" @retry="load"
        ><template v-if="account"
            ><div class="page-heading" data-tour="connection-heading">
                <div>
                    <p class="eyebrow">
                        {{ account.provider.name }} ·
                        {{ account.is_demo ? 'ДЕМО' : 'ВНЕШНЕЕ ПОДКЛЮЧЕНИЕ' }}
                    </p>
                    <h1>{{ account.name }}</h1>
                    <p>{{ account.configuration.shop_domain || account.status }}</p>
                </div>
                <button class="secondary" data-demo-write @click="edit = true">
                    Изменить подключение
                </button>
            </div>
            <div class="tabs" role="tablist" data-tour="connection-tabs">
                <button
                    v-for="t in [
                        { id: 'overview', name: 'Обзор' },
                        { id: 'products', name: 'Товары' },
                        ...(caps.includes('inventory') || account.is_demo
                            ? [{ id: 'inventory', name: 'Остатки' }]
                            : []),
                        { id: 'settings', name: 'Настройки' },
                        ...(caps.includes('sync') || caps.includes('history')
                            ? [{ id: 'history', name: 'История' }]
                            : []),
                    ]"
                    :key="t.id"
                    role="tab"
                    :aria-selected="tab === t.id"
                    @click="selectTab(t.id)"
                >
                    {{ t.name }}
                </button>
            </div>
            <section
                v-if="tab === 'overview'"
                class="panel section-card"
                data-tour="connection-overview"
            >
                <h2>Состояние подключения</h2>
                <p v-if="account.marketplace === 'shopify_demo'" class="info-banner">
                    Synthetic demo: товары и история созданы локально, Shopify API не вызывался.
                </p>
                <p v-if="account.is_demo" class="info-banner">
                    Демонстрационные данные. Внешняя синхронизация не поддерживается.
                </p>
                <p>API: {{ apiStatus(account.integration.api_status) }}</p>
                <p>
                    Внешних товаров {{ account.integration.products_count }} · вариантов
                    {{ account.integration.variants_count }} · несопоставленных
                    {{ account.integration.unmapped_count }}
                </p>
                <p>
                    Последняя успешная синхронизация:
                    {{
                        account.integration.last_synced_at
                            ? new Date(account.integration.last_synced_at).toLocaleString('ru-RU')
                            : 'Ещё не было'
                    }}
                </p>
                <p>
                    Следующий плановый запуск:
                    {{
                        account.integration.next_sync_at
                            ? new Date(account.integration.next_sync_at).toLocaleString('ru-RU')
                            : 'Не запланирован'
                    }}
                </p>
                <p class="fine-print">
                    Плановый запуск требует работающих Scheduler и worker. Сохранённое время снимка
                    не означает, что очередь сейчас работает.
                </p>
                <div class="inventory-actions">
                    <button
                        v-if="caps.includes('check_connection')"
                        class="secondary"
                        :disabled="busy || !!run"
                        data-demo-write
                        @click="check"
                    >
                        Проверить API</button
                    ><button
                        v-if="caps.includes('sync')"
                        class="primary"
                        :disabled="busy || !!run || account.status !== 'active'"
                        data-tour="connection-sync"
                        data-demo-write
                        @click="account.integration.initial_import_at ? sync() : (confirm = true)"
                    >
                        {{
                            account.integration.initial_import_at
                                ? 'Синхронизировать подключение'
                                : 'Выполнить первый импорт'
                        }}</button
                    ><button class="text-link" @click="selectTab('products')">
                        Товары подключения →
                    </button>
                </div>
                <p v-if="run" role="status" data-tour="connection-sync-status">
                    Задание {{ run.id }} · {{ syncStatus(run.status) }} · обработано
                    {{ run.processed }}. Результат сохраняется в истории.
                </p>
                <p v-if="account.integration.latest_run?.error_message" class="alert">
                    {{ account.integration.latest_run.error_message }}
                </p>
            </section>
            <MarketplaceProductList v-else-if="tab === 'products'" :account-id="id" embedded />
            <MarketplaceStocksPage v-else-if="tab === 'inventory'" :account-id="id" embedded />
            <section
                v-else-if="tab === 'settings'"
                class="panel section-card sync-settings"
                data-tour="connection-settings"
            >
                <h2>Настройки</h2>
                <form v-if="caps.includes('sync')" data-demo-write @submit.prevent="saveSettings">
                    <label
                        >Частота синхронизации<select v-model="frequency">
                            <option value="manual">Вручную</option>
                            <option value="hourly">Каждый час</option>
                            <option value="daily">Раз в сутки</option>
                        </select></label
                    ><label class="checkbox-label"
                        ><input v-model="products" type="checkbox" />Синхронизация товаров</label
                    ><label class="checkbox-label"
                        ><input v-model="inventory" type="checkbox" />Синхронизация остатков</label
                    ><button class="primary" :disabled="busy || !!run">Сохранить настройки</button>
                </form>
                <p v-else>Автоматическое расписание для demo недоступно.</p>
            </section>
            <MarketplaceSyncHistory
                v-else-if="tab === 'history' && (caps.includes('sync') || caps.includes('history'))"
                :account-id="id"
                :revision="revision"
            /> </template
    ></PageState>
    <div v-if="confirm" class="modal-backdrop">
        <section
            class="modal"
            role="dialog"
            aria-modal="true"
            aria-label="Подтвердить первый импорт"
        >
            <h2>Первый импорт</h2>
            <p>
                Будут прочитаны товары и остатки выбранного подключения. Локальные SKU не создаются
                автоматически. Физический склад не изменяется.
            </p>
            <div class="form-actions">
                <button class="secondary" :disabled="busy" data-demo-write @click="confirm = false">
                    Назад</button
                ><button class="primary" :disabled="busy" data-demo-write @click="sync">
                    Подтвердить импорт
                </button>
            </div>
        </section>
    </div>
    <div v-if="edit && account" class="modal-backdrop">
        <section class="modal" role="dialog" aria-modal="true" aria-label="Изменить подключение">
            <MarketplaceConnectionForm :account="account" @close="edit = false" @saved="saved" />
        </section>
    </div>
</template>
<style scoped>
.sync-settings form {
    display: grid;
    gap: 22px;
    max-width: 560px;
}
.sync-settings label {
    margin: 0;
    display: grid;
    gap: 8px;
}
.sync-settings .checkbox-label {
    display: flex;
    align-items: center;
    gap: 10px;
}
.tabs {
    display: flex;
    gap: 8px;
    overflow-x: auto;
    margin: 20px 0;
}
.tabs button {
    padding: 12px 18px;
    border: 1px solid var(--border);
    border-radius: 8px;
    background: white;
    white-space: nowrap;
}
.tabs button[aria-selected='true'] {
    color: var(--primary);
    border-color: var(--primary);
}
.inventory-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
}
</style>
