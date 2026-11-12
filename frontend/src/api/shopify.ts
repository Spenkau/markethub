import { client, csrf } from './client';
import type { MarketplaceAccount, Page, Params } from '../types';
export interface SyncRun {
    scope?: 'account' | 'product' | 'variant';
    attempt?: number;
    heartbeat_at?: string | null;
    dispatch_state?: string;
    attempt_errors?: { attempt: number; message: string; code: string }[];
    id: string;
    target_listing_id?: number | null;
    status: 'queued' | 'running' | 'completed' | 'partial' | 'failed';
    trigger: 'manual' | 'automatic';
    sync_products: boolean;
    sync_inventory: boolean;
    full_reconcile: boolean;
    processed: number;
    products_processed: number;
    variants_processed: number;
    inventory_processed: number;
    created: number;
    updated: number;
    skipped: number;
    errors_count: number;
    error_message: string | null;
    details: { reference: string; message: string }[] | null;
    created_at: string;
    started_at: string | null;
    finished_at: string | null;
}
export interface ShopifySummary {
    domain: string;
    api_status: string;
    api_checked_at: string | null;
    currency: string | null;
    sync_frequency: 'manual' | 'hourly' | 'daily';
    sync_products: boolean;
    sync_inventory: boolean;
    initial_import_at: string | null;
    last_synced_at: string | null;
    next_sync_at: string | null;
    products_count: number;
    variants_count: number;
    unmapped_count: number;
    latest_run: SyncRun | null;
}
export interface ShopifyListing {
    stock_quantity: number | null;
    id: number;
    product_id: number | null;
    external_sku: string | null;
    source_status: string;
    price_minor: number;
    currency: string;
    source_synced_at: string;
    local_name: string | null;
    local_sku: string | null;
    admin_url: string;
    source_data: {
        product: {
            id: string;
            title: string;
            vendor: string;
            images: { url: string; altText: string | null }[];
        };
        variant: {
            id: string;
            title: string;
            barcode: string | null;
            compareAtPrice: string | null;
        };
    };
}
export interface ShopifyLevel {
    id: number;
    product_id: number | null;
    title: string;
    external_sku: string | null;
    location_name: string;
    tracked: boolean;
    is_current: boolean;
    available: number | null;
    on_hand: number | null;
    committed: number | null;
    incoming: number | null;
    source_synced_at: string;
}
export interface ShopifySettings {
    sync_frequency: 'manual' | 'hourly' | 'daily';
    sync_products: boolean;
    sync_inventory: boolean;
    status: 'active' | 'disabled';
    name?: string;
    client_id?: string;
    client_secret?: string;
}
const base = (id: number) => `api/v1/shopify/connections/${id}`;
export const shopifyApi = {
    async product(id: number, listing: number) {
        return (
            await client.get<{
                data: ShopifyListing & { levels: ShopifyLevel[]; active_run?: SyncRun | null };
            }>(`${base(id)}/products/${listing}`)
        ).data.data;
    },
    async refreshProduct(id: number, listing: number) {
        await csrf();
        return (await client.post<{ data: SyncRun }>(`${base(id)}/products/${listing}/refresh`))
            .data.data;
    },
    async create(data: {
        name: string;
        shop_domain: string;
        client_id: string;
        client_secret: string;
    }) {
        await csrf();
        return (await client.post<{ data: MarketplaceAccount }>('api/v1/shopify/connections', data))
            .data.data;
    },
    async get(id: number) {
        return (await client.get<{ data: MarketplaceAccount }>(base(id))).data.data;
    },
    async settings(id: number, data: ShopifySettings) {
        await csrf();
        return (await client.patch<{ data: MarketplaceAccount }>(base(id), data)).data.data;
    },
    async check(id: number) {
        await csrf();
        return (
            await client.post<{ data: { shop_name: string; missing_scopes: string[] } }>(
                `${base(id)}/check`,
            )
        ).data.data;
    },
    async sync(id: number, confirm: boolean) {
        await csrf();
        return (
            await client.post<{ data: SyncRun }>(`${base(id)}/sync`, {
                confirm_initial_import: confirm,
            })
        ).data.data;
    },
    async products(id: number, params: Params) {
        return (await client.get<Page<ShopifyListing>>(`${base(id)}/products`, { params })).data;
    },
    async inventory(id: number, params: Params) {
        return (await client.get<Page<ShopifyLevel>>(`${base(id)}/inventory`, { params })).data;
    },
    async locations(id: number) {
        return (await client.get<{ data: { id: number; name: string }[] }>(`${base(id)}/locations`))
            .data.data;
    },
    async runs(id: number, params: Params) {
        return (await client.get<Page<SyncRun>>(`${base(id)}/runs`, { params })).data;
    },
    async run(id: number, run: string) {
        return (await client.get<{ data: SyncRun }>(`${base(id)}/runs/${run}`)).data.data;
    },
    async map(id: number, listing: number, product_id: number | null) {
        await csrf();
        await client.patch(`${base(id)}/products/${listing}/mapping`, { product_id });
    },
};
export const runNames: Record<SyncRun['status'], string> = {
    queued: 'В очереди',
    running: 'Выполняется',
    completed: 'Завершена',
    partial: 'Частично',
    failed: 'Ошибка',
};
