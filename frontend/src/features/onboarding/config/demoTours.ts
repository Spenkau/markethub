import { tours } from './tours';
import type { TourDefinition } from '../types';
const sections: Record<string, [string, string]> = {
    welcome: ['/app', 'dashboard-kpis'],
    products: ['/app/products', 'catalog-heading'],
    warehouses: ['/app/stocks', 'warehouse-list'],
    suppliers: ['/app/purchases?tab=suppliers', 'procurement-list'],
    purchases: ['/app/purchases', 'procurement-list'],
    marketplaces: ['/app/marketplaces', 'marketplace-heading'],
    shopify: ['/app/marketplaces', 'marketplace-heading'],
};
export const demoTours: TourDefinition[] = tours.map((tour) => {
    const [route, target] = sections[tour.id] || ['/app', 'dashboard-kpis'];
    return {
        ...tour,
        version: tour.version + 1000,
        steps: [
            {
                id: 'demo-boundary',
                title: 'Знакомство с MarketHub',
                description:
                    'Это синтетическая демокомпания. Можно изучать данные и фильтры; изменения и внешние API-вызовы запрещены.',
                route,
            },
            {
                id: 'demo-section',
                title: tour.title,
                description:
                    'Откройте карточки и списки, попробуйте фильтры. Пустое значение остатка означает «нет данных», подтверждённый ноль отображается отдельно.',
                route,
                target: `[data-tour="${target}"]`,
            },
            {
                id: 'demo-explore',
                title: 'Продолжайте просмотр',
                description:
                    'В каталоге доступны фотографии и экспорт локальных SKU. В подключениях можно просмотреть внешние варианты, остатки и синтетическую историю. Прогресс обучения хранится только в вашей вкладке.',
            },
        ],
    };
});
