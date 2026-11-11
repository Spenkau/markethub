<script setup lang="ts">
import {
    ArrowUpRight,
    Package,
    Layers,
    Store,
    ChartNoAxesCombined,
    ArrowRight,
} from 'lucide-vue-next';
const features = [
    { icon: Package, title: 'Товары', text: 'Единый каталог, цены и карточки товаров.' },
    {
        icon: Layers,
        title: 'Остатки',
        text: 'Размещения с нулевыми и низкими остатками — под рукой.',
    },
    {
        icon: Store,
        title: 'Маркетплейсы',
        text: 'Управление демонстрационными подключениями WB и Ozon.',
    },
    {
        icon: ChartNoAxesCombined,
        title: 'Обзор каталога',
        text: 'Доступные показатели каталога. Аналитика продаж — в планах.',
    },
];
</script>
<template>
    <div class="landing">
        <header class="landing-nav">
            <RouterLink to="/" class="brand"
                ><span class="brand-symbol">M</span>MarketHub<span class="brand-dot"
                    >.</span
                ></RouterLink
            ><RouterLink to="/login" class="secondary"
                >Войти в кабинет <ArrowUpRight :size="16"
            /></RouterLink>
        </header>
        <main>
            <section class="hero">
                <div class="eyebrow">
                    <span class="status-dot"></span>ВАШ КАТАЛОГ. ОДНО ПРОСТРАНСТВО.
                </div>
                <h1>Управляйте продажами на маркетплейсах <span>в одном месте</span></h1>
                <p>
                    Товары, размещения и остатки Wildberries и Ozon в понятном рабочем кабинете.
                    Меньше переключений — больше внимания бизнесу.
                </p>
                <RouterLink to="/login" class="primary"
                    >Войти в кабинет <ArrowRight :size="18"
                /></RouterLink>
                <div class="hero-note">Демонстрационные данные · Без реальных интеграций</div>
            </section>
            <section class="features">
                <article v-for="feature in features" :key="feature.title">
                    <div class="feature-icon">
                        <component :is="feature.icon" :size="23" />
                    </div>
                    <h2>{{ feature.title }}</h2>
                    <p>{{ feature.text }}</p>
                </article>
            </section>
            <section class="landing-bottom">
                <div>
                    <p class="eyebrow">ПОРЯДОК В ЕЖЕДНЕВНОЙ РАБОТЕ</p>
                    <h2>Всё начинается с понятного каталога.</h2>
                </div>
                <RouterLink to="/login" class="secondary"
                    >Открыть MarketHub <ArrowUpRight :size="18"
                /></RouterLink>
            </section>
        </main>
        <footer>MarketHub <span>Рабочее пространство продавца</span></footer>
    </div>
</template>
