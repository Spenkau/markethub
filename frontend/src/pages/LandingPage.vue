<script setup lang="ts">
import { ref, onMounted } from 'vue';
import { useRouter } from 'vue-router';
import { authApi } from '../api/auth';
import { useAuth } from '../stores/auth';
import { message } from '../api/client';
const available = ref(false),
    checking = ref(true),
    busy = ref(false),
    error = ref('');
const auth = useAuth(),
    router = useRouter();
onMounted(async () => {
    try {
        available.value = await authApi.demoStatus();
    } catch {
        available.value = false;
    } finally {
        checking.value = false;
    }
});
async function enterDemo() {
    busy.value = true;
    error.value = '';
    try {
        auth.user = await authApi.demoLogin();
        auth.ready = true;
        await router.push('/app');
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
}
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
        text: 'Внешние остатки отдельно от физических. Склады и журнал движений.',
    },
    {
        icon: Store,
        title: 'Маркетплейсы',
        text: 'Shopify: импорт товаров, вариантов и остатков, только чтение. WB/Ozon: demo.',
    },
    {
        icon: ChartNoAxesCombined,
        title: 'Рабочий обзор',
        text: 'Dashboard, поставщики, закупки и приёмка. Очереди и история синхронизации.',
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
            ><a href="https://jarovit.org/" class="secondary" target="_blank" rel="noreferrer"
                >Проект JAROVIT <ArrowUpRight :size="16"
            /></a>
        </header>
        <main>
            <section class="hero">
                <div class="eyebrow">
                    <span class="status-dot"></span>ВАШ КАТАЛОГ. ОДНО ПРОСТРАНСТВО.
                </div>
                <h1>Каталог, склады и площадки <span>в одном пространстве</span></h1>
                <p>
                    MarketHub — портфолио-проект разработчика JAROVIT. Единый каталог, сопоставление
                    вариантов и складской учёт в адаптивном рабочем кабинете.
                </p>
                <button
                    v-if="available"
                    class="primary"
                    :disabled="busy"
                    :aria-busy="busy"
                    @click="enterDemo"
                >
                    {{ busy ? 'Открываем демоверсию…' : 'Войти в демонстрационный аккаунт' }}
                    <ArrowRight :size="18" />
                </button>
                <p v-else class="hero-note" role="status">
                    {{
                        checking
                            ? 'Проверяем доступность demo…'
                            : 'Публичная демоверсия пока не подготовлена.'
                    }}
                </p>
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <div class="hero-note">
                    Demo: синтетические данные, только просмотр, без внешних API-вызовов.
                </div>
            </section>
            <figure class="landing-preview">
                <img
                    src="/demo-assets/workspace-preview.png"
                    alt="Настоящий интерфейс MarketHub: синтетический каталог, остатки и подключения"
                    width="1440"
                    height="900"
                    loading="lazy"
                />
                <figcaption class="sr-only">
                    Демонстрационные данные JAROVIT. Функции продаж и финансов не заявляются.
                </figcaption>
            </figure>
            <section id="features" class="features">
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
                    <p>
                        В обычной версии Shopify работает в read-only режиме. Покупательские заказы
                        и финансы не реализованы.
                    </p>
                </div>
                <a href="#features" class="secondary"
                    >Возможности проекта <ArrowUpRight :size="18"
                /></a>
            </section>
        </main>
        <footer>MarketHub · JAROVIT <span>Портфолио разработчика</span></footer>
    </div>
</template>
