<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { RefreshCw } from 'lucide-vue-next';
import GettingStarted from '../features/onboarding/components/GettingStarted.vue';
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import PageState from '../components/ui/PageState.vue';
import { dashboardApi, type DashboardOverview, type DashboardConnection } from '../api/dashboard';
import { connectionsApi, apiStatus, syncStatus } from '../api/connections';
import { ApiError, message } from '../api/client';
import { useAuth } from '../stores/auth';
const auth = useAuth();
const route = useRoute(),
    router = useRouter();
const data = ref<DashboardOverview>(),
    loading = ref(true),
    error = ref(''),
    actionError = ref(''),
    notice = ref(''),
    busy = ref<number>();
const selection = computed(() =>
    route.query.marketplace_account_id
        ? `account:${route.query.marketplace_account_id}`
        : String(route.query.provider || ''),
);
const includeDemo = computed(
    () => route.query.include_demo === '1' || (auth.isDemo && route.query.include_demo !== '0'),
);
let sequence = 0,
    disposed = false,
    timer: ReturnType<typeof setInterval>;
async function load(quiet = false) {
    const current = ++sequence;
    if (!quiet) loading.value = true;
    error.value = '';
    try {
        const result = await dashboardApi.overview({
            provider: route.query.provider ? String(route.query.provider) : undefined,
            marketplace_account_id: route.query.marketplace_account_id
                ? String(route.query.marketplace_account_id)
                : undefined,
            include_demo: includeDemo.value ? '1' : '0',
            origin: route.query.origin ? String(route.query.origin) : undefined,
        });
        if (current === sequence && !disposed) data.value = result;
    } catch (e) {
        if (current === sequence && !disposed) {
            if (e instanceof ApiError && e.status === 404 && route.query.marketplace_account_id) {
                notice.value =
                    'Подключение недоступно в текущей организации. Фильтр подключения сброшен.';
                await router.replace({
                    query: { ...route.query, marketplace_account_id: undefined },
                });
            } else error.value = message(e);
        }
    } finally {
        if (current === sequence && !disposed) loading.value = false;
    }
}
function selectScope(event: Event) {
    const value = (event.target as HTMLSelectElement).value;
    const a = value.startsWith('account:')
        ? data.value?.filters.accounts.find((a) => a.id === Number(value.slice(8)))
        : undefined;
    const demo = a?.is_demo || data.value?.filters.providers.find((p) => p.code === value)?.is_demo;
    void router.replace({
        query: {
            ...route.query,
            provider: a ? undefined : value || undefined,
            marketplace_account_id: a?.id,
            include_demo: demo ? '1' : includeDemo.value ? '1' : undefined,
        },
    });
}
function toggleDemo(event: Event) {
    void router.replace({
        query: {
            ...route.query,
            include_demo: (event.target as HTMLInputElement).checked ? '1' : undefined,
        },
    });
}
async function sync(a: DashboardConnection) {
    if (
        a.needs_initial_confirmation &&
        !window.confirm('Начать первоначальный импорт? Физические складские остатки не изменятся.')
    )
        return;
    busy.value = a.id;
    actionError.value = '';
    notice.value = '';
    try {
        await connectionsApi.sync(a.id, a.needs_initial_confirmation);
        notice.value = `Синхронизация «${a.name}» поставлена в очередь.`;
        await load(true);
    } catch (e) {
        actionError.value = message(e);
    } finally {
        busy.value = undefined;
    }
}
const date = (v: string | null) =>
    v
        ? new Date(v).toLocaleString('ru-RU', { dateStyle: 'short', timeStyle: 'short' })
        : 'Нет данных';
const cards = computed(() =>
    data.value
        ? [
              {
                  title: 'Локальные SKU',
                  value: data.value.products.local_skus,
                  note: 'Вся организация · без дублей площадок',
                  to: '/app/products?kind=local',
              },
              {
                  title: 'Внешние товары',
                  value: data.value.products.external_products,
                  note: 'Родительские карточки источников',
                  to: data.value.links.catalog,
              },
              {
                  title: 'Внешние варианты',
                  value: data.value.products.external_variants,
                  note: `${data.value.products.real_variants} реальных · ${data.value.products.demo_variants} demo · ${data.value.products.unmapped_variants} не сопоставлено`,
                  to: data.value.links.catalog,
              },
              {
                  title: 'Подключения',
                  value: data.value.connections.total,
                  note: `${data.value.connections.real} реальных · ${data.value.connections.demo} demo`,
                  to: '/app/marketplaces',
              },
          ]
        : [],
);
const total = computed(
    () => data.value?.provider_distribution.reduce((n, r) => n + r.variants, 0) || 0,
);
watch(
    () => route.fullPath,
    () => {
        void load();
    },
);
onMounted(() => {
    void load();
    timer = setInterval(() => {
        if (
            !document.hidden &&
            data.value?.connections.active_syncs &&
            !loading.value &&
            busy.value === undefined
        )
            void load(true);
    }, 10000);
});
onBeforeUnmount(() => {
    disposed = true;
    sequence++;
    clearInterval(timer);
});
</script>
<template>
    <div class="dashboard-page">
        <header class="page-heading">
            <div>
                <p class="eyebrow">РАБОЧИЙ ЦЕНТР</p>
                <h1>Обзор</h1>
                <p>Товары, подключения и наличие — с понятной областью учёта.</p>
            </div>
            <div class="dashboard-actions">
                <TourLauncher tour-id="welcome" /><button
                    class="secondary"
                    :disabled="loading"
                    @click="load()"
                >
                    <RefreshCw :size="16" />Обновить
                </button>
            </div>
        </header>
        <GettingStarted v-if="!data" />
        <div class="panel dashboard-filters" data-tour="dashboard-filters">
            <label
                >Область обзора<select
                    :value="selection"
                    aria-label="Область обзора"
                    @change="selectScope"
                >
                    <option value="">Весь бизнес</option>
                    <option v-for="p in data?.filters.providers" :key="p.code" :value="p.code">
                        {{ p.name }}{{ p.is_demo ? ' · demo' : '' }}
                    </option>
                    <optgroup label="Подключения">
                        <option
                            v-for="a in data?.filters.accounts"
                            :key="a.id"
                            :value="`account:${a.id}`"
                        >
                            {{ a.name }}{{ a.is_demo ? ' · demo' : '' }}
                        </option>
                    </optgroup>
                </select></label
            ><label class="demo-toggle"
                ><input type="checkbox" :checked="includeDemo" @change="toggleDemo" />Включить
                демонстрационные данные</label
            ><span v-if="data" class="fine-print">Сформировано {{ date(data.generated_at) }}</span>
        </div>
        <p v-if="includeDemo" class="info-banner" role="status">
            Включён demo-режим. Демонстрационные источники отмечены отдельно; их показатели не
            подтверждают работу реального API.
        </p>
        <p v-else-if="data?.connections.excluded_demo" class="context-note">
            Не включено demo-подключений: {{ data.connections.excluded_demo }}. Для просмотра
            включите демонстрационные данные.
        </p>
        <p v-if="actionError" class="error-banner" role="alert">{{ actionError }}</p>
        <p v-if="notice" class="info-banner" role="status">{{ notice }}</p>
        <PageState :loading="loading" :error="error" @retry="load()"
            ><template v-if="data">
                <section v-if="data.availability.empty_organization" class="panel section-card">
                    <h2>Начните с первого полезного действия</h2>
                    <p>
                        В организации пока нет данных для обзора. Чек-лист выше поможет настроить
                        учёт.
                    </p>
                    <div class="dashboard-actions">
                        <RouterLink to="/app/marketplaces" class="primary"
                            >Подключить площадку</RouterLink
                        ><RouterLink to="/app/products/new" class="secondary" data-demo-write
                            >Добавить товар</RouterLink
                        ><RouterLink to="/app/stocks?tab=warehouses" class="secondary"
                            >Создать склад</RouterLink
                        ><RouterLink to="/app/purchases?tab=suppliers" class="text-link"
                            >Добавить поставщика</RouterLink
                        >
                    </div>
                </section>
                <div class="dashboard-kpis" data-tour="dashboard-kpis">
                    <RouterLink
                        v-for="c in cards"
                        :key="c.title"
                        :to="c.to"
                        class="panel dashboard-kpi"
                        ><span>{{ c.title }}</span
                        ><strong>{{ c.value.toLocaleString('ru-RU') }}</strong
                        ><small>{{ c.note }}</small></RouterLink
                    >
                </div>
                <p class="context-note">
                    Локальные SKU и внешние карточки не складываются в «уникальные товары». В
                    выбранных источниках представлено локальных SKU:
                    {{ data.products.represented_local_skus }}. Склады и закупки относятся ко всей
                    организации.
                </p>
                <section class="panel section-card" data-tour="dashboard-attention">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">ПРИОРИТЕТЫ</p>
                            <h2>Требует внимания</h2>
                        </div>
                        <span class="badge">{{ data.attention.length }} категорий</span>
                    </div>
                    <div v-if="data.attention.length" class="dashboard-attention">
                        <RouterLink
                            v-for="item in data.attention"
                            :key="item.id"
                            :to="item.url"
                            class="attention-item"
                            :data-severity="item.severity"
                            ><div>
                                <strong
                                    >{{ item.title }}
                                    <span
                                        class="badge"
                                        :class="item.severity === 'info' ? '' : 'warning'"
                                        >{{ item.count }}</span
                                    ></strong
                                >
                                <p>{{ item.description }}</p>
                            </div>
                            <span aria-hidden="true">→</span></RouterLink
                        >
                    </div>
                    <p v-else class="inline-empty">
                        По доступным данным нет задач, требующих внимания. Это не подтверждает
                        наличие там, где учёт ещё не ведётся.
                    </p>
                </section>
                <GettingStarted />
                <section class="panel section-card" data-tour="dashboard-connections">
                    <div class="section-heading">
                        <div>
                            <p class="eyebrow">ИСТОЧНИКИ</p>
                            <h2>Подключения и синхронизация</h2>
                        </div>
                        <RouterLink to="/app/marketplaces" class="text-link"
                            >Все подключения →</RouterLink
                        >
                    </div>
                    <p class="fine-print">
                        С проблемами: {{ data.connections.problems }} · Активных синхронизаций:
                        {{ data.connections.active_syncs }}
                    </p>
                    <div class="dashboard-connection-grid">
                        <article
                            v-for="a in data.connection_rows"
                            :key="a.id"
                            class="dashboard-connection"
                        >
                            <div class="section-heading">
                                <div>
                                    <span class="eyebrow">{{ a.provider_name }}</span>
                                    <h3>
                                        <RouterLink :to="a.url">{{ a.name }}</RouterLink>
                                    </h3>
                                </div>
                                <span
                                    class="badge"
                                    :class="
                                        a.is_demo
                                            ? 'warning'
                                            : a.status === 'active'
                                              ? 'success'
                                              : ''
                                    "
                                    >{{
                                        a.is_demo
                                            ? 'DEMO'
                                            : a.status === 'active'
                                              ? 'Реальное API'
                                              : 'Отключено'
                                    }}</span
                                >
                            </div>
                            <p v-if="a.is_demo" class="fine-print">
                                Демонстрационные данные · подключения к API нет.
                            </p>
                            <template v-else
                                ><p>
                                    Доступ: {{ apiStatus(a.api_status) }} · Проверка:
                                    {{ date(a.api_checked_at) }}
                                </p>
                                <p>
                                    Последний запуск:
                                    {{
                                        a.latest_run
                                            ? syncStatus(a.latest_run.status)
                                            : 'Не запускалась'
                                    }}
                                </p>
                                <p class="fine-print">
                                    Успешно: {{ date(a.last_success_at) }}<br />{{
                                        a.sync_frequency === 'manual'
                                            ? 'Расписание: вручную'
                                            : `Следующий запуск: ${date(a.next_sync_at)}`
                                    }}
                                </p></template
                            >
                            <p>{{ a.variants }} вариантов · {{ a.unmapped }} не сопоставлено</p>
                            <p v-if="a.problem" class="field-error">{{ a.problem }}</p>
                            <div class="dashboard-actions">
                                <RouterLink :to="a.url" class="text-link">Открыть</RouterLink
                                ><RouterLink :to="a.catalog_url" class="text-link"
                                    >Товары</RouterLink
                                ><RouterLink
                                    v-if="a.history_url"
                                    :to="a.history_url"
                                    class="text-link"
                                    >История</RouterLink
                                ><button
                                    v-if="a.can_sync"
                                    class="secondary"
                                    :disabled="
                                        busy !== undefined ||
                                        ['queued', 'running'].includes(a.latest_run?.status || '')
                                    "
                                    data-demo-write
                                    @click="sync(a)"
                                >
                                    {{ busy === a.id ? 'Запускаем…' : 'Синхронизировать сейчас' }}
                                </button>
                            </div>
                        </article>
                    </div>
                    <p v-if="!data.connection_rows.length" class="inline-empty">
                        В выбранной области нет подключений.
                        <RouterLink to="/app/marketplaces">Добавить подключение</RouterLink>
                    </p>
                    <p v-if="data.connections_truncated" class="context-note">
                        Показаны первые 50 подключений. KPI рассчитаны для всей выбранной области.
                    </p>
                </section>
                <div class="dashboard-columns">
                    <section v-if="total" class="panel section-card">
                        <p class="eyebrow">ПРЕДСТАВЛЕННОСТЬ</p>
                        <h2>Размещения по площадкам</h2>
                        <p class="fine-print">
                            Внешние варианты, не уникальные локальные товары. Demo и происхождение
                            разделены.
                        </p>
                        <div
                            v-for="r in data.provider_distribution"
                            :key="`${r.provider}-${r.is_demo}-${r.origin}`"
                            class="distribution-row"
                        >
                            <div>
                                <strong>{{
                                    data.filters.providers.find((p) => p.code === r.provider)
                                        ?.name || r.provider
                                }}</strong
                                ><span
                                    >{{ r.variants }} · {{ r.is_demo ? 'DEMO' : 'реальные' }} ·
                                    {{ r.origin }}</span
                                >
                            </div>
                            <div class="distribution-track">
                                <span :style="{ width: `${(r.variants / total) * 100}%` }"></span>
                            </div>
                        </div>
                        <RouterLink :to="data.links.catalog" class="text-link"
                            >Открыть общий каталог →</RouterLink
                        >
                    </section>
                    <section class="panel section-card">
                        <p class="eyebrow">ВНЕШНИЕ СНИМКИ</p>
                        <h2>Остатки площадок</h2>
                        <dl class="dashboard-metrics">
                            <div>
                                <dt>Мало · 1–5 по всем локациям</dt>
                                <dd>{{ data.external_inventory.low_variants }} вариантов</dd>
                            </div>
                            <div>
                                <dt>Известный ноль</dt>
                                <dd>{{ data.external_inventory.zero_variants }}</dd>
                            </div>
                            <div>
                                <dt>У импортированных вариантов нет снимка</dt>
                                <dd>{{ data.external_inventory.no_snapshot_variants }}</dd>
                            </div>
                            <div>
                                <dt>Нет полного актуального количества</dt>
                                <dd>{{ data.external_inventory.unknown_variants }}</dd>
                            </div>
                            <div>
                                <dt>Есть отрицательный остаток</dt>
                                <dd>{{ data.external_inventory.negative_variants }}</dd>
                            </div>
                            <div>
                                <dt>Отслеживание отключено</dt>
                                <dd>{{ data.external_inventory.untracked_variants }}</dd>
                            </div>
                            <div>
                                <dt>Устаревшие снимки локаций</dt>
                                <dd>{{ data.external_inventory.stale_snapshots }}</dd>
                            </div>
                        </dl>
                        <p class="context-note">
                            Неизвестные и устаревшие количества не участвуют в низком остатке.
                            Внешние снимки не прибавляются к физическим.
                        </p>
                        <RouterLink :to="data.links.inventory" class="text-link"
                            >Открыть внешние остатки →</RouterLink
                        >
                    </section>
                    <section class="panel section-card">
                        <p class="eyebrow">ВСЯ ОРГАНИЗАЦИЯ</p>
                        <h2>Физический склад</h2>
                        <dl class="dashboard-metrics">
                            <div>
                                <dt>Активные склады</dt>
                                <dd>{{ data.physical.warehouses }}</dd>
                            </div>
                            <div data-testid="dashboard-physical-units">
                                <dt>Единиц в учёте</dt>
                                <dd>{{ data.physical.units }}</dd>
                            </div>
                            <div>
                                <dt>SKU в учёте</dt>
                                <dd>{{ data.physical.tracked_skus }}</dd>
                            </div>
                            <div>
                                <dt>Мало · 1–5 суммарно</dt>
                                <dd>{{ data.physical.low_skus }}</dd>
                            </div>
                            <div>
                                <dt>Подтверждённый ноль</dt>
                                <dd>{{ data.physical.zero_skus }}</dd>
                            </div>
                            <div>
                                <dt>Нет складских записей</dt>
                                <dd>{{ data.physical.no_data_skus }}</dd>
                            </div>
                        </dl>
                        <p class="context-note">
                            Сумма по складам для каждого SKU. Отсутствие записи не считается нулём.
                        </p>
                        <RouterLink to="/app/stocks" class="text-link"
                            >Склады и остатки →</RouterLink
                        >
                    </section>
                    <section class="panel section-card">
                        <p class="eyebrow">ВСЯ ОРГАНИЗАЦИЯ</p>
                        <h2>Закупки и приёмки</h2>
                        <dl class="dashboard-metrics">
                            <div>
                                <dt>Открытые закупки</dt>
                                <dd>{{ data.procurement.open_orders }}</dd>
                            </div>
                            <div>
                                <dt>Черновики заказов</dt>
                                <dd>{{ data.procurement.draft_orders }}</dd>
                            </div>
                            <div>
                                <dt>
                                    <RouterLink to="/app/purchases?status=ordered"
                                        >Заказаны, ожидают приёмки</RouterLink
                                    >
                                </dt>
                                <dd>{{ data.procurement.awaiting_receipt }}</dd>
                            </div>
                            <div>
                                <dt>
                                    <RouterLink to="/app/purchases?status=partially_received"
                                        >Частично приняты</RouterLink
                                    >
                                </dt>
                                <dd>{{ data.procurement.partially_received }}</dd>
                            </div>
                            <div>
                                <dt>
                                    <RouterLink to="/app/purchases?tab=receipts&amp;status=draft"
                                        >Черновики приёмок</RouterLink
                                    >
                                </dt>
                                <dd>{{ data.procurement.draft_receipts }}</dd>
                            </div>
                        </dl>
                        <RouterLink to="/app/purchases" class="text-link"
                            >Открыть закупки →</RouterLink
                        >
                    </section>
                </div>
                <section class="panel section-card">
                    <p class="eyebrow">ИСТОРИЯ</p>
                    <h2>Последние операции</h2>
                    <p class="fine-print">
                        Синхронизации выбранных подключений; движения и подтверждённые приёмки всей
                        организации.
                    </p>
                    <div v-if="data.activity.length" class="dashboard-activity">
                        <RouterLink
                            v-for="event in data.activity"
                            :key="`${event.kind}-${event.id}`"
                            :to="event.url"
                            ><div>
                                <strong>{{
                                    event.kind === 'sync'
                                        ? syncStatus(event.description)
                                        : event.description
                                }}</strong
                                ><span
                                    >{{ event.source }} ·
                                    {{
                                        event.is_demo
                                            ? 'DEMO'
                                            : event.kind === 'sync'
                                              ? 'Синхронизация'
                                              : 'Учёт организации'
                                    }}</span
                                >
                            </div>
                            <time :datetime="event.occurred_at">{{
                                date(event.occurred_at)
                            }}</time></RouterLink
                        >
                    </div>
                    <p v-else class="inline-empty">
                        Операций пока нет. Здесь появятся реальные запуски, движения и
                        подтверждённые приёмки.
                    </p>
                </section>
            </template></PageState
        >
    </div>
</template>
