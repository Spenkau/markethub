<script setup lang="ts">
import GettingStarted from '../features/onboarding/components/GettingStarted.vue';
import TourLauncher from '../features/onboarding/components/TourLauncher.vue';
import { computed, onMounted, ref } from 'vue';
import {
    Package,
    Store,
    TriangleAlert,
    ArrowUpRight,
    Plus,
    RefreshCw,
    ArrowRight,
    Layers,
} from 'lucide-vue-next';
import { productsApi } from '../api/products';
import { marketplacesApi } from '../api/marketplaces';
import { stocksApi } from '../api/stocks';
import { inventoryApi, type InventorySummary } from '../api/inventory';
import { message } from '../api/client';
import type { Product, Stock, MarketplaceAccount } from '../types';
import PageState from '../components/ui/PageState.vue';
import { money } from '../utils/money';
const loading = ref(true),
    error = ref(''),
    counts = ref<number[]>([]);
const recent = ref<Product[]>([]),
    problems = ref<Stock[]>([]),
    accounts = ref<MarketplaceAccount[]>([]);
const inventory = ref<InventorySummary>();
const inventoryError = ref('');
const updated = ref('');
const cards = computed(() => [
    {
        title: 'Товары в каталоге',
        icon: Package,
        to: '/app/products',
        value: counts.value[0],
        detail: `${counts.value[1] ?? 0} активных`,
        tone: 'blue',
    },
    {
        title: 'Размещения',
        icon: Layers,
        to: '/app/marketplace-stocks',
        value: counts.value[2],
        detail: 'На всех маркетплейсах',
        tone: 'purple',
    },
    {
        title: 'Требуют внимания',
        icon: TriangleAlert,
        to: '/app/marketplace-stocks?stock=zero',
        value: (counts.value[3] ?? 0) + (counts.value[4] ?? 0),
        detail: `${counts.value[3] ?? 0} без остатка · ${counts.value[4] ?? 0} мало`,
        tone: 'amber',
    },
    {
        title: 'Подключения',
        icon: Store,
        to: '/app/marketplaces',
        value: counts.value[5],
        detail: `${counts.value[6] ?? 0} со статусом «Активно»`,
        tone: 'green',
    },
]);
const distribution = computed(() => [
    {
        label: 'Нет в наличии',
        count: counts.value[3] ?? 0,
        color: '#e45c70',
        to: '/app/marketplace-stocks?stock=zero',
    },
    {
        label: 'Мало · 1–5 шт.',
        count: counts.value[4] ?? 0,
        color: '#e7a83e',
        to: '/app/marketplace-stocks?stock=low',
    },
    {
        label: 'Более 5 шт.',
        count: Math.max(
            0,
            (counts.value[2] ?? 0) - (counts.value[3] ?? 0) - (counts.value[4] ?? 0),
        ),
        color: '#37b99b',
        to: '/app/marketplace-stocks',
    },
]);
const activePercent = computed(() =>
    counts.value[0] ? Math.round(((counts.value[1] ?? 0) / counts.value[0]) * 100) : 0,
);
async function load() {
    void loadInventory();
    loading.value = true;
    error.value = '';
    try {
        const [all, active, stocks, zero, low, connections, enabled, latest] = await Promise.all([
            productsApi.list({ per_page: 1 }),
            productsApi.list({ per_page: 1, is_active: 1 }),
            stocksApi.list({ per_page: 1 }),
            stocksApi.list({ per_page: 4, stock: 'zero' }),
            stocksApi.list({ per_page: 4, stock: 'low', sort: 'stock_quantity' }),
            marketplacesApi.list({ per_page: 4, sort: 'created_at', direction: 'desc' }),
            marketplacesApi.list({ per_page: 1, status: 'active' }),
            productsApi.list({ per_page: 5, sort: 'created_at', direction: 'desc' }),
        ]);
        counts.value = [
            all.meta.total,
            active.meta.total,
            stocks.meta.total,
            zero.meta.total,
            low.meta.total,
            connections.meta.total,
            enabled.meta.total,
        ];
        problems.value = [...zero.data, ...low.data].slice(0, 5);
        recent.value = latest.data;
        accounts.value = connections.data;
        updated.value = new Date().toLocaleTimeString('ru-RU', {
            hour: '2-digit',
            minute: '2-digit',
        });
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
async function loadInventory() {
    inventoryError.value = '';
    try {
        inventory.value = await inventoryApi.summary();
    } catch (e) {
        inventory.value = undefined;
        inventoryError.value = message(e);
    }
}
onMounted(load);
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">ВАШ БИЗНЕС · ПОД КОНТРОЛЕМ</p>
            <h1>Обзор бизнеса</h1>
            <p>Каталог, наличие и каналы продаж в одном рабочем пространстве.</p>
        </div>
        <div class="heading-actions">
            <button class="secondary" :disabled="loading" aria-label="Обновить обзор" @click="load">
                <RefreshCw :size="16" /></button
            ><RouterLink to="/app/products/new" class="primary"
                ><Plus :size="17" />Добавить товар</RouterLink
            >
        </div>
    </div>
    <TourLauncher tour-id="welcome" /><GettingStarted />
    <PageState :loading="loading" :error="error" @retry="load">
        <div class="overview-caption">
            <span class="live-label"><span class="status-dot"></span>Текущий срез организации</span
            ><span>Обновлено в {{ updated }}</span>
        </div>
        <div class="stats-grid" data-tour="dashboard-kpis">
            <RouterLink
                v-for="card in cards"
                :key="card.title"
                :to="card.to"
                class="stat-card"
                :class="card.tone"
                ><div class="stat-top">
                    <span class="metric-icon"><component :is="card.icon" :size="20" /></span
                    ><ArrowUpRight :size="16" />
                </div>
                <span>{{ card.title }}</span
                ><strong>{{ card.value?.toLocaleString('ru-RU') }}</strong
                ><small>{{ card.detail }}</small></RouterLink
            >
        </div>
        <div class="dashboard-grid">
            <section class="panel section-card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">НАЛИЧИЕ</p>
                        <h2>Остатки на площадках</h2>
                    </div>
                    <RouterLink to="/app/marketplace-stocks" class="text-link"
                        >Все остатки <ArrowUpRight :size="14"
                    /></RouterLink>
                </div>
                <p class="section-description">
                    Распределение по размещениям, не по уникальным товарам.
                </p>
                <div
                    v-if="counts[2]"
                    class="stock-distribution"
                    role="img"
                    :aria-label="distribution.map((x) => `${x.label}: ${x.count}`).join(', ')"
                >
                    <span
                        v-for="segment in distribution"
                        :key="segment.label"
                        :style="{
                            width: `${(segment.count / (counts[2] || 1)) * 100}%`,
                            background: segment.color,
                        }"
                    ></span>
                </div>
                <p v-else class="inline-empty">
                    Размещений пока нет. Распределение появится вместе с данными.
                </p>
                <div class="distribution-legend">
                    <RouterLink
                        v-for="segment in distribution"
                        :key="segment.label"
                        :to="segment.to"
                        ><span class="legend-dot" :style="{ background: segment.color }"></span
                        ><span>{{ segment.label }}</span
                        ><strong>{{ segment.count }}</strong></RouterLink
                    >
                </div>
            </section>
            <section class="panel section-card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">АССОРТИМЕНТ</p>
                        <h2>Активность каталога</h2>
                    </div>
                    <Package :size="20" class="muted" />
                </div>
                <div class="catalog-chart">
                    <div
                        class="donut"
                        :style="{
                            background: `conic-gradient(#5564e8 ${activePercent}%, #edf0f7 0)`,
                        }"
                        role="img"
                        :aria-label="`Активных товаров ${activePercent}%`"
                    >
                        <div>
                            <strong>{{ counts[0] ? `${activePercent}%` : '—' }}</strong
                            ><span>активны</span>
                        </div>
                    </div>
                    <div>
                        <strong class="chart-number">{{ counts[1] }}</strong>
                        <p>активных товаров</p>
                        <span class="badge"
                            >{{ (counts[0] ?? 0) - (counts[1] ?? 0) }} неактивных</span
                        >
                    </div>
                </div>
            </section>
            <section class="panel">
                <div class="section-heading padded">
                    <div>
                        <p class="eyebrow">ПРИОРИТЕТЫ</p>
                        <h2>Нужно пополнить</h2>
                    </div>
                    <span class="badge warning"
                        >{{ (counts[3] ?? 0) + (counts[4] ?? 0) }} размещений</span
                    >
                </div>
                <div v-if="problems.length" class="table-scroll">
                    <table>
                        <thead>
                            <tr>
                                <th>Товар / канал</th>
                                <th>Остаток</th>
                                <th>Цена</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="item in problems" :key="item.id">
                                <td>
                                    <RouterLink
                                        :to="`/app/marketplace-stocks?product_id=${item.product.id}`"
                                        class="product-name"
                                        >{{ item.product.name }}</RouterLink
                                    ><span class="table-subtitle"
                                        >{{ item.marketplace_account.name }} ·
                                        {{ item.product.sku }}</span
                                    >
                                </td>
                                <td>
                                    <span
                                        class="badge"
                                        :class="item.stock_quantity === 0 ? 'critical' : 'warning'"
                                        >{{ item.stock_quantity }} шт.</span
                                    >
                                </td>
                                <td class="nowrap">{{ money(item.price_minor, item.currency) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="inline-empty">
                    <strong>{{
                        counts[2] ? 'С остатками всё в порядке' : 'Пока нет размещений'
                    }}</strong>
                    <p>Здесь появятся позиции с остатком до 5 единиц.</p>
                </div>
                <RouterLink to="/app/marketplace-stocks?stock=low" class="panel-link"
                    >Открыть низкие остатки <ArrowRight :size="16"
                /></RouterLink>
            </section>
            <section class="panel section-card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">КАНАЛЫ ПРОДАЖ</p>
                        <h2>Подключения</h2>
                    </div>
                    <RouterLink to="/app/marketplaces" class="text-link"
                        >Все <ArrowUpRight :size="14"
                    /></RouterLink>
                </div>
                <div v-if="accounts.length" class="connection-list">
                    <RouterLink
                        v-for="account in accounts"
                        :key="account.id"
                        to="/app/marketplaces"
                        class="connection-row"
                        ><span class="market-logo" :class="account.marketplace">{{
                            account.marketplace === 'ozon' ? 'O' : 'WB'
                        }}</span>
                        <div>
                            <strong>{{ account.name }}</strong
                            ><span
                                >{{ account.listings_count ?? 0 }} размещений ·
                                {{ account.is_demo ? 'Демо' : 'Локальный режим' }}</span
                            >
                        </div>
                        <span class="badge" :class="account.status === 'active' ? 'success' : ''">{{
                            { active: 'Активно', demo: 'Демо', disabled: 'Отключено' }[
                                account.status
                            ]
                        }}</span></RouterLink
                    >
                </div>
                <p v-else class="inline-empty">Добавьте первое подключение маркетплейса.</p>
                <p class="context-note">
                    Статусы сохранены в MarketHub. Обмен с Ozon и Wildberries пока не подключён.
                </p>
            </section>
            <section class="panel section-card">
                <div class="section-heading">
                    <div>
                        <p class="eyebrow">КАТАЛОГ</p>
                        <h2>Недавно добавлены</h2>
                    </div>
                    <RouterLink to="/app/products" class="text-link"
                        >Все товары <ArrowUpRight :size="14"
                    /></RouterLink>
                </div>
                <div v-if="recent.length" class="activity-list">
                    <RouterLink
                        v-for="product in recent"
                        :key="product.id"
                        :to="`/app/products/${product.id}`"
                        class="activity-row"
                        ><span class="activity-icon"><Package :size="16" /></span>
                        <div>
                            <strong>{{ product.name }}</strong
                            ><span>Создан товар · {{ product.sku }}</span>
                        </div>
                        <time :datetime="product.created_at">{{
                            new Date(product.created_at).toLocaleDateString('ru-RU', {
                                day: 'numeric',
                                month: 'short',
                            })
                        }}</time></RouterLink
                    >
                </div>
                <p v-else class="inline-empty">
                    Создайте первый товар, чтобы начать работу с каталогом.
                </p>
            </section>
            <section class="panel roadmap-card">
                <span class="badge">Следующий шаг</span>
                <h2>От каталога — к продажам</h2>
                <p>
                    Выручка, заказы и динамика продаж появятся после подключения источников данных.
                    Сейчас обзор помогает управлять ассортиментом и наличием.
                </p>
                <RouterLink to="/app/marketplaces" class="secondary"
                    >Управлять каналами <ArrowRight :size="16"
                /></RouterLink>
            </section>
        </div>
        <section class="panel section-card warehouse-overview">
            <div class="section-heading">
                <div>
                    <p class="eyebrow">ФИЗИЧЕСКИЙ УЧЁТ</p>
                    <h2>Склады организации</h2>
                </div>
                <RouterLink to="/app/stocks" class="text-link"
                    >Склады и остатки <ArrowUpRight :size="14"
                /></RouterLink>
            </div>
            <div v-if="inventory" class="warehouse-overview-metrics">
                <div>
                    <strong>{{ inventory.active_warehouses }}</strong
                    ><span>активных складов</span>
                </div>
                <div>
                    <strong>{{ inventory.units.toLocaleString('ru-RU') }}</strong
                    ><span>единиц товара</span>
                </div>
                <div>
                    <strong>{{ inventory.products }}</strong
                    ><span>товаров в учёте</span>
                </div>
                <div>
                    <strong>{{ inventory.zero_positions }}</strong
                    ><span>нулевых позиций</span>
                </div>
            </div>
            <p v-else-if="inventoryError" class="fine-print">
                {{ inventoryError }}
                <button class="text-link" @click="loadInventory">Повторить</button>
            </p>
            <p v-else class="fine-print">Загружаем складской учёт…</p>
            <p class="context-note">
                Физические остатки учитываются отдельно. Остатки размещений не суммируются со
                складскими.
            </p>
        </section>
    </PageState>
</template>
