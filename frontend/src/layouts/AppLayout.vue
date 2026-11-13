<script setup lang="ts">
import { ref, onMounted, onBeforeUnmount, watch, nextTick } from 'vue';
import {
    CircleHelp,
    ShoppingCart,
    LayoutDashboard,
    Package,
    Layers,
    Store,
    LogOut,
    Menu,
    X,
    ChevronRight,
    Users,
} from 'lucide-vue-next';
import TourHost from '../features/onboarding/components/TourHost.vue';
import { useAuth } from '../stores/auth';
import { router } from '../router';
import { authApi } from '../api/auth';
import { message } from '../api/client';
const auth = useAuth(),
    open = ref(false),
    busy = ref(false),
    error = ref(''),
    organizationVersion = ref(0),
    organizationsLoading = ref(true);
const collapsed = ref(false);
const mobile = ref(window.innerWidth < 768),
    sidebar = ref<HTMLElement>();
let menuOpener: HTMLElement | null = null;
const media = window.matchMedia('(max-width: 767px)');
function resize() {
    mobile.value = media.matches;
    if (!mobile.value) open.value = false;
}
media.addEventListener('change', resize);
onBeforeUnmount(() => media.removeEventListener('change', resize));
watch(open, async (value) => {
    if (value) {
        menuOpener = document.activeElement as HTMLElement;
        await nextTick();
        sidebar.value?.querySelector<HTMLButtonElement>('.mobile-close')?.focus();
    } else if (menuOpener?.isConnected) menuOpener.focus();
});
function menuKey(event: KeyboardEvent) {
    if (!mobile.value || !open.value || event.key !== 'Tab') return;
    const controls = Array.from(
        sidebar.value?.querySelectorAll<HTMLElement>(
            'a[href],button:not(:disabled),select:not(:disabled)',
        ) || [],
    ).filter((el) => el.getBoundingClientRect().width);
    const first = controls[0],
        last = controls.at(-1);
    if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last?.focus();
    } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first?.focus();
    }
}
const links = [
    { path: '/app', title: 'Обзор', icon: LayoutDashboard },
    { path: '/app/products', title: 'Товары', icon: Package },
    { path: '/app/stocks', title: 'Склады и остатки', icon: Layers },
    { path: '/app/marketplace-stocks', title: 'Остатки площадок', icon: Store },
    { path: '/app/marketplaces', title: 'Маркетплейсы', icon: Store },
    { path: '/app/purchases?tab=suppliers', title: 'Поставщики', icon: Users },
    { path: '/app/purchases', title: 'Закупки и поставки', icon: ShoppingCart },
    { path: '/app/help', title: 'Помощь и обучение', icon: CircleHelp },
];
onMounted(async () => {
    try {
        await auth.loadOrganizations();
    } catch (e) {
        error.value = message(e);
    } finally {
        organizationsLoading.value = false;
    }
});
async function logout() {
    busy.value = true;
    error.value = '';
    try {
        await auth.logout();
        await router.push('/login');
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
}
async function select(event: Event) {
    busy.value = true;
    error.value = '';
    try {
        await authApi.select(Number((event.target as HTMLSelectElement).value));
        auth.user = await authApi.me();
        organizationVersion.value++;
    } catch (e) {
        error.value = message(e);
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <a class="skip-link" href="#main-content">Перейти к содержимому</a>
    <div
        class="app-shell"
        :class="{ 'demo-readonly': auth.isDemo, 'sidebar-collapsed': collapsed }"
        @keydown.esc="open = false"
    >
        <button
            v-if="open"
            class="nav-scrim"
            aria-label="Закрыть меню"
            @click="open = false"
        ></button>
        <aside
            id="workspace-navigation"
            ref="sidebar"
            class="sidebar"
            :class="{ opened: open }"
            :inert="mobile && !open"
            aria-label="Основная навигация"
            @keydown="menuKey"
        >
            <RouterLink to="/" class="brand"
                ><span class="brand-symbol">M</span>MarketHub<span class="brand-dot"
                    >.</span
                ></RouterLink
            ><button
                class="mobile-close secondary"
                aria-label="Закрыть меню"
                data-tour="close-navigation"
                @click="open = false"
            >
                <X :size="20" />
            </button>
            <div class="workspace-tag"><span class="status-dot"></span>Управление торговлей</div>
            <p class="nav-label">РАБОЧЕЕ ПРОСТРАНСТВО</p>
            <nav data-tour="main-navigation">
                <RouterLink
                    v-for="link in links"
                    :key="link.path"
                    :data-tour="link.path === '/app/help' ? 'help-navigation' : undefined"
                    :to="link.path"
                    :title="link.title"
                    :class="{
                        selected: link.path.includes('?')
                            ? $route.path === '/app/purchases' && $route.query.tab === 'suppliers'
                            : link.path === '/app'
                              ? $route.path === link.path
                              : $route.path.startsWith(link.path) &&
                                !(
                                    link.path === '/app/purchases' &&
                                    $route.query.tab === 'suppliers'
                                ),
                    }"
                    @click="open = false"
                    ><component :is="link.icon" :size="19" /><span>{{
                        link.title
                    }}</span></RouterLink
                >
            </nav>
            <label class="mobile-organization">
                Организация
                <select
                    :value="auth.user?.active_organization_id"
                    :disabled="busy || auth.isDemo"
                    aria-label="Организация в мобильном меню"
                    @change="select"
                >
                    <option v-for="org in auth.organizations" :key="org.id" :value="org.id">
                        {{ org.name }}
                    </option>
                </select>
            </label>
            <div class="sidebar-note">
                <span class="status-dot"></span>Каталог и наличие
                <p>Shopify импортирует данные. Wildberries и Ozon доступны в demo.</p>
            </div>
            <div class="sidebar-footer">MarketHub <span>Рабочий кабинет</span></div>
        </aside>
        <div class="workspace">
            <header class="topbar">
                <div class="flex items-center gap-3">
                    <button
                        class="secondary desktop-collapse"
                        :aria-expanded="!collapsed"
                        aria-label="Свернуть или развернуть меню"
                        @click="collapsed = !collapsed"
                    >
                        <Menu :size="16" />
                    </button>
                    <button
                        class="mobile-menu secondary"
                        aria-label="Открыть меню"
                        :aria-expanded="open"
                        aria-controls="workspace-navigation"
                        data-tour="open-navigation"
                        @click="open = true"
                    >
                        <Menu :size="20" /></button
                    ><span class="muted">Кабинет</span><ChevronRight :size="14" /><strong>{{
                        $route.meta.title
                    }}</strong>
                </div>
                <div class="organization-bar" data-tour="organization-switcher">
                    <span>Организация</span
                    ><select
                        v-if="auth.organizations.length"
                        :value="auth.user?.active_organization_id"
                        :disabled="busy || auth.isDemo"
                        aria-label="Активная организация"
                        @change="select"
                    >
                        <option v-for="org in auth.organizations" :key="org.id" :value="org.id">
                            {{ org.name }}
                        </option></select
                    ><span v-else>{{
                        organizationsLoading
                            ? 'Загружаем организации…'
                            : 'Нет доступных организаций'
                    }}</span>
                    <RouterLink v-if="!auth.isDemo" to="/app?create=organization" class="text-link"
                        >+ Организация</RouterLink
                    >
                </div>
                <div class="user-controls">
                    <span class="topbar-mode">Рабочее пространство</span>
                    <span class="avatar">{{ auth.user?.name.slice(0, 1).toUpperCase() }}</span
                    ><span>{{ auth.user?.name }}</span
                    ><button
                        class="icon-button"
                        :disabled="busy"
                        title="Выйти"
                        aria-label="Выйти"
                        @click="logout"
                    >
                        <LogOut :size="18" />
                    </button>
                </div>
            </header>
            <main id="main-content" class="content">
                <div v-if="auth.isDemo" class="info-banner" role="status" data-testid="demo-banner">
                    <strong>Демонстрационный режим</strong> · Синтетические данные, только просмотр.
                    Редактирование и обращения к внешним API недоступны. Фильтры, экспорт и обучение
                    работают.
                </div>
                <p v-if="error" class="alert" role="alert">
                    {{ error }}
                </p>
                <RouterView
                    :key="`${auth.user?.active_organization_id}-${organizationVersion}-${$route.path}`"
                />
            </main>
            <footer class="workspace-footer">
                MarketHub · Единое пространство для вашего каталога
            </footer>
        </div>
        <TourHost />
    </div>
</template>
<style>
.demo-readonly [data-demo-write] {
    display: none !important;
}
</style>
