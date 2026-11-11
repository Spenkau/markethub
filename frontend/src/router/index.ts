import { createRouter, createWebHistory } from 'vue-router';
import { useAuth } from '../stores/auth';
export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', component: () => import('../pages/LandingPage.vue') },
        { path: '/login', component: () => import('../pages/LoginPage.vue') },
        {
            path: '/app',
            component: () => import('../layouts/AppLayout.vue'),
            meta: { requiresAuth: true },
            children: [
                {
                    path: '',
                    component: () => import('../pages/DashboardPage.vue'),
                    meta: { title: 'Обзор' },
                },
                {
                    path: 'products',
                    component: () => import('../pages/ProductsPage.vue'),
                    meta: { title: 'Товары' },
                },
                {
                    path: 'products/new',
                    component: () => import('../pages/ProductFormPage.vue'),
                    meta: { title: 'Новый товар' },
                },
                {
                    path: 'products/:id/edit',
                    component: () => import('../pages/ProductFormPage.vue'),
                    meta: { title: 'Редактирование товара' },
                },
                {
                    path: 'products/:id',
                    component: () => import('../pages/ProductDetailPage.vue'),
                    meta: { title: 'Карточка товара' },
                },
                {
                    path: 'marketplaces',
                    component: () => import('../pages/MarketplacesPage.vue'),
                    meta: { title: 'Маркетплейсы' },
                },
                {
                    path: 'stocks',
                    component: () => import('../pages/StocksPage.vue'),
                    meta: { title: 'Остатки' },
                },
            ],
        },
        { path: '/:pathMatch(.*)*', component: () => import('../pages/NotFoundPage.vue') },
    ],
});
router.beforeEach(async (to) => {
    if (to.meta.requiresAuth || to.path === '/login') {
        const auth = useAuth();
        await auth.restore();
        if (!auth.user && to.meta.requiresAuth)
            return { path: '/login', query: { redirect: to.fullPath } };
        if (auth.user && to.path === '/login') return '/app';
    }
});
