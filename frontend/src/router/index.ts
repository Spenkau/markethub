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
                    path: 'help',
                    component: () => import('../pages/HelpPage.vue'),
                    meta: { title: 'Помощь и обучение' },
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
                    component: () => import('../pages/CatalogDetailPage.vue'),
                    meta: { title: 'Карточка товара' },
                },
                {
                    path: 'purchases',
                    component: () => import('../pages/ProcurementPage.vue'),
                    meta: { title: 'Закупки и поставки' },
                },
                {
                    path: 'purchases/receipts/:id',
                    component: () => import('../pages/GoodsReceiptPage.vue'),
                    meta: { title: 'Документ приёмки' },
                },
                {
                    path: 'purchases/:id',
                    component: () => import('../pages/PurchaseOrderPage.vue'),
                    meta: { title: 'Закупка' },
                },
                {
                    path: 'marketplaces',
                    component: () => import('../pages/MarketplacesPage.vue'),
                    meta: { title: 'Маркетплейсы' },
                },
                {
                    path: 'marketplaces/shopify/new',
                    redirect: '/app/marketplaces?connect=shopify',
                    meta: { title: 'Подключить Shopify' },
                },
                {
                    path: 'marketplaces/shopify/:id/products/:listing',
                    redirect: (to) => ({
                        path: `/app/catalog/external_variant/${to.params.listing}`,
                        query: to.query,
                    }),
                    meta: { title: 'Товар Shopify' },
                },
                {
                    path: 'marketplaces/shopify/:id',
                    redirect: (to) => ({
                        path: `/app/marketplaces/${to.params.id}`,
                        query: to.query,
                    }),
                    meta: { title: 'Shopify' },
                },
                {
                    path: 'catalog/:kind/:id',
                    component: () => import('../pages/CatalogDetailPage.vue'),
                    meta: { title: 'Карточка товара' },
                },
                {
                    path: 'marketplaces/:id(\\d+)',
                    component: () => import('../pages/MarketplaceConnectionPage.vue'),
                    meta: { title: 'Подключение площадки' },
                },
                {
                    path: 'marketplace-stocks',
                    component: () => import('../pages/MarketplaceStocksPage.vue'),
                    meta: { title: 'Остатки маркетплейсов' },
                },
                {
                    path: 'stocks',
                    component: () => import('../pages/StocksPage.vue'),
                    meta: { title: 'Склады и остатки' },
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
