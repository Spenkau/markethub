<script setup lang="ts">
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { ref, reactive, onMounted, watch, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Plus, Pencil, Store, Search, ArrowUpRight } from 'lucide-vue-next';
import { shortDate } from '../utils/date';
import { marketplacesApi } from '../api/marketplaces';
import { ApiError, csrf, message } from '../api/client';
import type { Page, MarketplaceAccount, AccountInput } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
import FieldError from '../components/ui/FieldError.vue';
import { shopifyApi } from '../api/shopify';
const router = useRouter();
const route = useRoute();
const credentials = reactive({ shop_domain: '', client_id: '', client_secret: '' });
const channelCounts = ref<number[]>([]);
const search = ref(''),
    marketplace = ref(String(useRoute().query.marketplace || '')),
    accountStatus = ref(''),
    mode = ref(''),
    sort = ref('id');
let sequence = 0;
let timer: ReturnType<typeof setTimeout>;
const result = ref<Page<MarketplaceAccount>>(),
    page = ref(1),
    loading = ref(true),
    error = ref(''),
    modal = ref(false),
    id = ref<number>(),
    busy = ref(false),
    saveError = ref(''),
    fields = ref<Record<string, string[]>>({});
const form = reactive<AccountInput>({
    name: '',
    marketplace: 'ozon',
    status: 'demo',
    is_demo: true,
});
const statusNames = { demo: 'Демо', active: 'Активно', disabled: 'Отключено' };
async function load() {
    const current = ++sequence;
    loading.value = true;
    error.value = '';
    try {
        const response = await marketplacesApi.list({
            page: page.value,
            search: search.value || undefined,
            marketplace: marketplace.value || undefined,
            status: accountStatus.value || undefined,
            is_demo: mode.value || undefined,
            sort: sort.value,
        });
        if (current === sequence) result.value = response;
    } catch (e) {
        if (current === sequence) error.value = message(e);
    } finally {
        if (current === sequence) loading.value = false;
    }
}
watch([search, marketplace, accountStatus, mode, sort], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
onBeforeUnmount(() => {
    clearTimeout(timer);
    sequence++;
});
function resetFilters() {
    search.value = '';
    marketplace.value = '';
    accountStatus.value = '';
    mode.value = '';
}
async function loadChannelCounts() {
    try {
        const values = await Promise.all(
            ['ozon', 'wildberries'].map((marketplace) =>
                marketplacesApi.list({ marketplace, per_page: 1 }),
            ),
        );
        channelCounts.value = values.map((v) => v.meta.total);
    } catch {
        channelCounts.value = [];
    }
}
onMounted(() => {
    void load();
    void loadChannelCounts();
    if (route.query.connect === 'shopify') {
        edit();
        form.marketplace = 'shopify';
    }
});
async function changePage(value: number) {
    page.value = value;
    await load();
}
function edit(account?: MarketplaceAccount) {
    id.value = account?.id;
    Object.assign(
        form,
        account
            ? {
                  name: account.name,
                  marketplace: account.marketplace,
                  status: account.status,
                  is_demo: account.is_demo,
              }
            : { name: '', marketplace: 'ozon', status: 'demo', is_demo: true },
    );
    Object.assign(credentials, { shop_domain: '', client_id: '', client_secret: '' });
    fields.value = {};
    saveError.value = '';
    modal.value = true;
}
async function save() {
    if (busy.value) return;
    busy.value = true;
    saveError.value = '';
    fields.value = {};
    try {
        await csrf();
        if (form.marketplace === 'shopify') {
            const account = await shopifyApi.create({ name: form.name, ...credentials });
            modal.value = false;
            Object.assign(credentials, { shop_domain: '', client_id: '', client_secret: '' });
            await router.push(`/app/marketplaces/shopify/${account.id}`);
            return;
        }
        await marketplacesApi.save({ ...form }, id.value);
        modal.value = false;
        await Promise.all([load(), loadChannelCounts()]);
    } catch (e) {
        saveError.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="page-heading" data-tour="marketplace-heading">
        <div>
            <p class="eyebrow">КАНАЛЫ ПРОДАЖ</p>
            <h1>Маркетплейсы</h1>
            <p>Подключения вашей организации.</p>
        </div>
        <button class="primary" data-tour="create-marketplace" @click="edit()">
            <Plus :size="18" />Добавить подключение
        </button>
    </div>
    <TourLauncher tour-id="marketplaces" />
    <div class="list-summary">
        <div>
            <span class="summary-icon"><Store :size="19" /></span>
            <div>
                <strong>{{ result?.meta.total ?? '—' }}</strong
                ><span>Подключений по условиям</span>
            </div>
        </div>
        <div>
            <strong>{{ channelCounts[0] ?? '—' }}</strong
            ><span>Подключений Ozon · всего</span>
        </div>
        <div>
            <strong>{{ channelCounts[1] ?? '—' }}</strong
            ><span>Подключений Wildberries · всего</span>
        </div>
    </div>
    <div class="info-banner">
        Подключения работают локально. Обмена данными с Wildberries и Ozon пока нет.
    </div>
    <section class="panel filters-panel">
        <div class="toolbar">
            <div class="search-input">
                <Search :size="18" /><input
                    v-model="search"
                    maxlength="100"
                    placeholder="Найти подключение"
                    aria-label="Поиск подключений"
                />
            </div>
            <select v-model="marketplace" aria-label="Маркетплейс">
                <option value="">Все маркетплейсы</option>
                <option value="ozon">Ozon</option>
                <option value="wildberries">Wildberries</option>
                <option value="shopify">Shopify</option></select
            ><select v-model="accountStatus" aria-label="Фильтр статуса">
                <option value="">Все статусы</option>
                <option value="active">Активные</option>
                <option value="demo">Демо</option>
                <option value="disabled">Отключённые</option></select
            ><select v-model="mode" aria-label="Режим подключения">
                <option value="">Все режимы</option>
                <option value="1">Демонстрационный</option>
                <option value="0">Локальный</option></select
            ><select v-model="sort" aria-label="Сортировка подключений">
                <option value="id">По ID</option>
                <option value="name">По названию</option>
                <option value="created_at">По дате создания</option></select
            ><button
                v-if="search || marketplace || accountStatus || mode"
                class="text-link"
                @click="resetFilters"
            >
                Сбросить
            </button>
        </div>
    </section>
    <PageState :loading="loading" :error="error" :empty="!result?.data.length" @retry="load">
        <div class="accounts-grid">
            <article v-for="account in result?.data" :key="account.id" class="panel account-card">
                <div class="account-top">
                    <div class="market-logo" :class="account.marketplace">
                        {{
                            account.marketplace === 'shopify'
                                ? 'S'
                                : account.marketplace === 'ozon'
                                  ? 'O'
                                  : 'WB'
                        }}
                    </div>
                    <span class="badge" :class="account.status === 'active' ? 'success' : ''">{{
                        statusNames[account.status]
                    }}</span>
                </div>
                <h2>{{ account.name }}</h2>
                <p>
                    {{
                        account.marketplace === 'shopify'
                            ? 'Shopify'
                            : account.marketplace === 'ozon'
                              ? 'Ozon'
                              : 'Wildberries'
                    }}
                </p>
                <dl>
                    <dt>Режим</dt>
                    <dd>{{ account.is_demo ? 'Демонстрационный' : 'Локальный' }}</dd>
                    <dt>Обновлено</dt>
                    <dd>{{ shortDate(account.updated_at) }}</dd>
                    <dt>Размещения</dt>
                    <dd>{{ account.listings_count ?? '—' }}</dd>
                </dl>
                <RouterLink
                    :to="
                        account.marketplace === 'shopify'
                            ? `/app/marketplaces/shopify/${account.id}?tab=products`
                            : `/app/marketplace-stocks?marketplace_account_id=${account.id}`
                    "
                    class="account-stock-link"
                    >Открыть размещения <ArrowUpRight :size="15"
                /></RouterLink>
                <template v-if="account.shopify"
                    ><p>{{ account.shopify.domain }}</p>
                    <p>
                        API:
                        {{
                            account.shopify.api_status === 'connected'
                                ? 'Проверен'
                                : 'Требует проверки'
                        }}
                    </p>
                    <p>
                        Товаров {{ account.shopify.products_count }} · вариантов
                        {{ account.shopify.variants_count }} · не сопоставлено
                        {{ account.shopify.unmapped_count }}
                    </p>
                    <p>
                        Последняя успешная синхронизация:
                        {{ shortDate(account.shopify.last_synced_at ?? undefined) }}
                    </p>
                    <p>Следующая: {{ shortDate(account.shopify.next_sync_at ?? undefined) }}</p>
                    <p v-if="account.shopify.latest_run">
                        Задание: {{ account.shopify.latest_run.status }}
                    </p>
                    <RouterLink :to="`/app/marketplaces/shopify/${account.id}`" class="primary full"
                        >Управлять Shopify</RouterLink
                    ></template
                >
                <button v-else class="secondary full" @click="edit(account)">
                    <Pencil :size="15" />Редактировать
                </button>
            </article>
        </div> </PageState
    ><PaginationBar v-if="result" :meta="result.meta" :disabled="loading" @change="changePage" />
    <div v-if="modal" class="modal-backdrop" @keydown.esc="!busy && (modal = false)">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="account-title">
            <Store :size="25" class="blue" />
            <h2 id="account-title">
                {{ id ? 'Редактировать подключение' : 'Новое подключение' }}
            </h2>
            <form @submit.prevent="save">
                <p v-if="saveError" class="alert" role="alert">
                    {{ saveError }}
                </p>
                <label
                    >Название<input
                        v-model="form.name"
                        required
                        maxlength="255"
                        autofocus /><FieldError :errors="fields.name" /></label
                ><label
                    >Маркетплейс<select v-model="form.marketplace" aria-label="Маркетплейс">
                        <option value="ozon">Ozon</option>
                        <option value="wildberries">Wildberries</option>
                        <option value="shopify">Shopify</option></select
                    ><FieldError :errors="fields.marketplace" /></label
                ><template v-if="form.marketplace !== 'shopify'"
                    ><label
                        >Статус<select v-model="form.status">
                            <option value="demo">Демо</option>
                            <option value="active">Активно</option>
                            <option value="disabled">Отключено</option></select
                        ><FieldError :errors="fields.status" /></label
                    ><label class="checkbox-label"
                        ><input v-model="form.is_demo" type="checkbox" />Демонстрационный
                        режим</label
                    ><FieldError :errors="fields.is_demo" />
                    <p class="fine-print">
                        Статус «Активно» — локальный признак подключения.
                    </p></template
                >
                <template v-else>
                    <label
                        >Домен магазина<input
                            v-model="credentials.shop_domain"
                            placeholder="store.myshopify.com"
                            required /><FieldError :errors="fields.shop_domain"
                    /></label>
                    <label
                        >Client ID<input
                            v-model="credentials.client_id"
                            autocomplete="off"
                            required /><FieldError :errors="fields.client_id"
                    /></label>
                    <label
                        >Client Secret<input
                            v-model="credentials.client_secret"
                            type="password"
                            autocomplete="new-password"
                            required /><FieldError :errors="fields.client_secret"
                    /></label>
                    <p class="fine-print">
                        После подключения проверьте доступ и подтвердите первый импорт. По умолчанию
                        товары и остатки обновляются каждый час.
                    </p>
                </template>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="modal = false">
                        Отмена</button
                    ><button class="primary" :disabled="busy">
                        {{ busy ? 'Сохраняем…' : 'Сохранить' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
