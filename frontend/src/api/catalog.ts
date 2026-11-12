import { client } from './client';
import type { Page, Params } from '../types';
export type CatalogKind = 'local' | 'external_product' | 'external_variant';
export interface Image {
    url: string;
    alt: string;
}
export interface Run {
    id: string;
    status: string;
    scope: string;
    target_listing_id: number | null;
    processed: number;
    errors_count: number;
    error_message: string | null;
    attempt_errors?: { message: string }[];
    created_at: string;
    finished_at: string | null;
}
export interface Source {
    local_product?: { id: number; name: string; sku: string } | null;
    id: number;
    product_id: number | null;
    marketplace_product_id: number | null;
    title: string | null;
    variant_title: string | null;
    sku: string | null;
    barcode: string | null;
    external_id: string;
    origin: string;
    status: string;
    price_minor: number;
    currency: string;
    source_synced_at: string | null;
    source_updated_at: string | null;
    account: {
        id: number;
        name: string;
        provider: string;
        provider_name: string;
        is_demo: boolean;
    };
    capabilities: string[];
    admin_url: string | null;
    active_run: Run | null;
    images: Image[];
    inventory: {
        quantity: number | null;
        tracked: boolean | null;
        current: boolean | null;
        levels: number;
        synced_at: string | null;
        origin: string;
    };
}
export interface CatalogEntry {
    key: string;
    kind: CatalogKind;
    entity_id: number;
    title: string;
    sku: string | null;
    price_minor: number;
    currency: string;
    status: string;
    mapping: string;
    images: Image[];
    sources: Source[];
    sources_count: number;
    physical_quantity: number | null;
    source_synced_at: string | null;
    updated_at: string;
}
export interface CatalogDetail {
    key: string;
    kind: CatalogKind;
    entity_id: number;
    title: string;
    sku: string | null;
    description: string | null;
    images: Image[];
    local_product: {
        id: number;
        sku: string;
        base_price_minor: number;
        currency: string;
        is_active: boolean;
        updated_at: string;
    } | null;
    sources: Source[];
    sources_count: number;
    sources_page: number;
    sources_last_page: number;
    physical_stocks: {
        product_id?: number;
        sku?: string;
        warehouse_id: number;
        warehouse_name: string;
        quantity: number;
    }[];
    inventory: {
        id: number;
        marketplace_listing_id: number;
        location_name: string;
        available: number | null;
        tracked: boolean;
        is_current: boolean;
        stale_reason: string | null;
        source_synced_at: string;
        location_active: boolean;
    }[];
    inventory_total: number;
}
export const catalogApi = {
    async list(params: Params = {}) {
        return (await client.get<Page<CatalogEntry>>('api/v1/catalog', { params })).data;
    },
    async detail(kind: string, id: string, page = 1) {
        return (
            await client.get<{ data: CatalogDetail }>(`api/v1/catalog/${kind}/${id}`, {
                params: { page },
            })
        ).data.data;
    },
    async map(listing: number, product: number | null) {
        return client.patch(`api/v1/marketplace-listings/${listing}/mapping`, {
            product_id: product,
        });
    },
    async createLocal(listing: number, sku: string, name?: string) {
        return client.post(`api/v1/marketplace-listings/${listing}/local-product`, { sku, name });
    },
    async refresh(listing: number) {
        return (await client.post<{ data: Run }>(`api/v1/marketplace-listings/${listing}/refresh`))
            .data.data;
    },
    async run(account: number, id: string) {
        return (
            await client.get<{ data: Run }>(
                `api/v1/marketplace-accounts/${account}/sync-runs/${id}`,
            )
        ).data.data;
    },
};
