<script setup lang="ts">
import { ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { connectionsApi, type Connection, type Provider } from '../api/connections';
import type { Page } from '../types';
import { message } from '../api/client';
import { client } from '../api/client';
import MarketplaceConnectionForm from '../components/marketplaces/MarketplaceConnectionForm.vue';
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
const route = useRoute(),
    router = useRouter(),
    data = ref<Page<Connection>>(),
    providers = ref<Provider[]>([]),
    search = ref(''),
    provider = ref(String(route.query.marketplace || '')),
    status = ref(''),
    page = ref(1),
    loading = ref(true),
    error = ref(''),
    modal = ref(false),
    editing = ref<Connection>();
let timer: ReturnType<typeof setTimeout>,
    seq = 0;
async function load() {
    const v = ++seq;
    loading.value = true;
    error.value = '';
    try {
        const result = (
            await client.get<Page<Connection>>('api/v1/marketplace-accounts', {
                params: {
                    page: page.value,
                    search: search.value || undefined,
                    marketplace: provider.value || undefined,
                    status: status.value || undefined,
                },
            })
        ).data;
        if (v === seq) data.value = result;
    } catch (e) {
        if (v === seq) error.value = message(e);
    } finally {
        if (v === seq) loading.value = false;
    }
}
watch([search, provider, status], () => {
    page.value = 1;
    clearTimeout(timer);
    timer = setTimeout(load, 300);
});
async function saved(account: Connection) {
    modal.value = false;
    await router.push(`/app/marketplaces/${account.id}`);
}
function create() {
    editing.value = undefined;
    modal.value = true;
}
async function edit(account: Connection) {
    editing.value = account;
    modal.value = true;
}
onMounted(async () => {
    void load();
    try {
        providers.value = await connectionsApi.providers();
    } catch (e) {
        error.value = message(e);
    }
    if (route.query.connect) create();
});
onBeforeUnmount(() => {
    seq++;
    clearTimeout(timer);
});
</script>
<template>
    <div class="page-heading" data-tour="marketplace-heading">
        <div>
            <p class="eyebrow">КАНАЛЫ ПРОДАЖ</p>
            <h1>Маркетплейсы</h1>
            <p>Подключения вашей организации.</p>
        </div>
        <button class="primary" data-tour="create-marketplace" @click="create">
            Добавить подключение
        </button>
    </div>
    <TourLauncher tour-id="marketplaces" />
    <div class="info-banner">
        Shopify читает реальные данные. Wildberries и Ozon пока доступны в demo без внешнего API.
    </div>
    <section class="panel">
        <div class="toolbar">
            <input
                v-model="search"
                aria-label="Поиск подключений"
                placeholder="Найти подключение"
                maxlength="100"
            /><select v-model="provider" aria-label="Маркетплейс">
                <option value="">Все площадки</option>
                <option v-for="p in providers" :key="p.code" :value="p.code">
                    {{ p.name }}
                </option></select
            ><select v-model="status" aria-label="Фильтр статуса">
                <option value="">Все статусы</option>
                <option value="active">Активные</option>
                <option value="demo">Демо</option>
                <option value="disabled">Отключённые</option>
            </select>
        </div>
    </section>
    <PageState :loading="loading" :error="error" :empty="!data?.data.length" @retry="load"
        ><div class="accounts-grid">
            <article v-for="a in data?.data" :key="a.id" class="panel account-card">
                <div class="account-top">
                    <span class="market-logo" :class="a.marketplace">{{
                        a.provider.name.slice(0, 2)
                    }}</span
                    ><span class="badge">{{
                        a.status === 'disabled' ? 'Отключено' : a.is_demo ? 'Демо' : 'Активно'
                    }}</span>
                </div>
                <h2>{{ a.name }}</h2>
                <p>{{ a.provider.name }} · {{ a.is_demo ? 'Демонстрационный' : 'Реальное API' }}</p>
                <p>
                    Вариантов: {{ a.integration.variants_count }} · несопоставленных
                    {{ a.integration.unmapped_count }}
                </p>
                <p v-if="!a.is_demo">
                    Последняя успешная синхронизация:
                    {{
                        a.integration.last_synced_at
                            ? new Date(a.integration.last_synced_at).toLocaleString('ru-RU')
                            : 'Ещё не было'
                    }}
                </p>
                <RouterLink :to="`/app/marketplaces/${a.id}`" class="primary full"
                    >Открыть подключение</RouterLink
                ><RouterLink
                    :to="`/app/marketplaces/${a.id}?tab=products`"
                    class="account-stock-link"
                    >Открыть размещения →</RouterLink
                ><button class="secondary full" @click="edit(a)">Редактировать</button>
            </article>
        </div></PageState
    ><PaginationBar
        v-if="data"
        :meta="data.meta"
        @change="
            (v) => {
                page = v;
                load();
            }
        "
    />
    <div v-if="modal" class="modal-backdrop" @keydown.esc="modal = false">
        <section class="modal" role="dialog" aria-modal="true" aria-label="Подключение площадки">
            <MarketplaceConnectionForm
                :key="editing?.id || 'new'"
                :account="editing"
                :initial-provider="route.query.connect ? String(route.query.connect) : undefined"
                @close="modal = false"
                @saved="saved"
            />
        </section>
    </div>
</template>
