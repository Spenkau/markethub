<script setup lang="ts">
import { ref, onMounted } from 'vue';
import {
    LayoutDashboard,
    Package,
    Layers,
    Store,
    LogOut,
    Menu,
    X,
    ChevronRight,
} from 'lucide-vue-next';
import { useAuth } from '../stores/auth';
import { router } from '../router';
import { authApi } from '../api/auth';
import { message } from '../api/client';
const auth = useAuth(),
    open = ref(false),
    busy = ref(false),
    error = ref(''),
    organizationVersion = ref(0);
const links = [
    { path: '/app', title: 'Обзор', icon: LayoutDashboard },
    { path: '/app/products', title: 'Товары', icon: Package },
    { path: '/app/stocks', title: 'Остатки', icon: Layers },
    { path: '/app/marketplaces', title: 'Маркетплейсы', icon: Store },
];
onMounted(async () => {
    try {
        await auth.loadOrganizations();
    } catch (e) {
        error.value = message(e);
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
    <div class="app-shell">
        <button
            v-if="open"
            class="nav-scrim"
            aria-label="Закрыть меню"
            @click="open = false"
        ></button>
        <aside class="sidebar" :class="{ opened: open }">
            <RouterLink to="/" class="brand"
                ><span class="brand-symbol">M</span>MarketHub<span class="brand-dot"
                    >.</span
                ></RouterLink
            ><button class="mobile-close secondary" aria-label="Закрыть меню" @click="open = false">
                <X :size="20" />
            </button>
            <p class="nav-label">РАБОЧЕЕ ПРОСТРАНСТВО</p>
            <nav>
                <RouterLink
                    v-for="link in links"
                    :key="link.path"
                    :to="link.path"
                    :class="{
                        selected:
                            link.path === '/app'
                                ? $route.path === link.path
                                : $route.path.startsWith(link.path),
                    }"
                    @click="open = false"
                    ><component :is="link.icon" :size="19" />{{ link.title }}</RouterLink
                >
            </nav>
            <div class="sidebar-note">
                <span class="status-dot"></span>Демонстрационный режим
                <p>Подключения пока не обмениваются данными с маркетплейсами.</p>
            </div>
            <div class="sidebar-footer">MarketHub <span>Рабочий кабинет</span></div>
        </aside>
        <div class="workspace">
            <header class="topbar">
                <div class="flex items-center gap-3">
                    <button
                        class="mobile-menu secondary"
                        aria-label="Открыть меню"
                        @click="open = true"
                    >
                        <Menu :size="20" /></button
                    ><span class="muted">Кабинет</span><ChevronRight :size="14" /><strong>{{
                        $route.meta.title
                    }}</strong>
                </div>
                <div class="user-controls">
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
            <main class="content">
                <div class="organization-bar">
                    <span>Организация</span
                    ><select
                        v-if="auth.organizations.length"
                        :value="auth.user?.active_organization_id"
                        :disabled="busy"
                        aria-label="Активная организация"
                        @change="select"
                    >
                        <option v-for="org in auth.organizations" :key="org.id" :value="org.id">
                            {{ org.name }}
                        </option></select
                    ><span v-else>Нет доступных организаций</span>
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
    </div>
</template>
