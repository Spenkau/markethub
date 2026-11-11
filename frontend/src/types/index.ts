export interface User {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    active_organization_id: number | null;
}
export interface Organization {
    id: number;
    name: string;
    slug: string;
}
export interface Product {
    id: number;
    organization_id: number;
    sku: string;
    name: string;
    description: string | null;
    price_minor: number;
    currency: string;
    is_active: boolean;
    listings_count?: number;
    created_at: string;
    updated_at: string;
}
export type ProductInput = Pick<
    Product,
    'sku' | 'name' | 'description' | 'price_minor' | 'currency' | 'is_active'
>;
export interface MarketplaceAccount {
    id: number;
    name: string;
    marketplace: 'wildberries' | 'ozon';
    status: 'demo' | 'active' | 'disabled';
    is_demo: boolean;
    listings_count?: number;
}
export type AccountInput = Omit<MarketplaceAccount, 'id' | 'listings_count'>;
export interface Stock {
    id: number;
    product: Pick<Product, 'id' | 'name' | 'sku'>;
    marketplace_account: Pick<MarketplaceAccount, 'id' | 'name' | 'marketplace' | 'is_demo'>;
    stock_quantity: number;
    price_minor: number;
    currency: string;
    status: 'active' | 'inactive';
}
export interface Page<T> {
    data: T[];
    meta: { current_page: number; last_page: number; per_page: number; total: number };
    links: { first: string; last: string; prev: string | null; next: string | null };
}
export type Params = Record<string, string | number | undefined>;
