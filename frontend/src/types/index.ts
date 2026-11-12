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
    shopify_listings?: {
        id: number;
        account_id: number;
        account_name: string;
        external_id: string;
        price_minor: number;
        currency: string;
        status: string;
        synced_at: string | null;
    }[];
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
    marketplace: 'wildberries' | 'ozon' | 'shopify';
    shopify?: import('../api/shopify').ShopifySummary | null;
    status: 'demo' | 'active' | 'disabled';
    is_demo: boolean;
    listings_count?: number;
    created_at?: string;
    updated_at?: string;
}
export type AccountInput = Pick<MarketplaceAccount, 'name' | 'marketplace' | 'status' | 'is_demo'>;
export interface Stock {
    inventory_level_id?: number | null;
    marketplace_location_id?: number | null;
    location_name?: string | null;
    location_active?: boolean | null;
    warehouse_id?: number | null;
    tracked?: boolean | null;
    is_current?: boolean | null;
    inventory_origin?: string;
    inventory_source_synced_at?: string | null;
    external_sku?: string | null;
    id: number;
    source_title?: string | null;
    source_synced_at?: string | null;
    marketplace_account_id: number;
    product: Pick<Product, 'id' | 'name' | 'sku'> | null;
    marketplace_account: Pick<MarketplaceAccount, 'id' | 'name' | 'marketplace' | 'is_demo'>;
    stock_quantity: number | null;
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
