import { client } from './client';
import type { Params } from '../types';
export interface DashboardConnection {
    id: number;
    name: string;
    provider: string;
    provider_name: string;
    is_demo: boolean;
    status: string;
    api_status: string;
    api_checked_at: string | null;
    sync_frequency: string;
    last_success_at: string | null;
    next_sync_at: string | null;
    latest_run: { id: string; status: string; created_at: string } | null;
    variants: number;
    unmapped: number;
    problem: string | null;
    can_sync: boolean;
    needs_initial_confirmation: boolean;
    url: string;
    catalog_url: string;
    history_url: string | null;
}
export interface DashboardOverview {
    generated_at: string;
    scope: {
        organization_id: number;
        provider: string | null;
        marketplace_account_id: number | null;
        include_demo: boolean;
        origin: string | null;
        organization_wide: string[];
        low_threshold: number;
    };
    products: {
        local_skus: number;
        real_variants: number;
        demo_variants: number;
        external_products: number;
        external_variants: number;
        represented_local_skus: number;
        unmapped_variants: number;
    };
    connections: {
        total: number;
        real: number;
        demo: number;
        excluded_demo: number;
        active_syncs: number;
        problems: number;
        overdue_syncs: number;
    };
    physical: {
        tracked_skus: number;
        zero_skus: number;
        low_skus: number;
        no_data_skus: number;
        units: number;
        warehouses: number;
    };
    external_inventory: {
        variants: number;
        low_variants: number;
        zero_variants: number;
        unknown_variants: number;
        no_snapshot_variants: number;
        negative_variants: number;
        untracked_variants: number;
        stale_variants: number;
        stale_snapshots: number;
        snapshots: number;
    };
    procurement: {
        open_orders: number;
        awaiting_receipt: number;
        partially_received: number;
        draft_orders: number;
        draft_receipts: number;
    };
    connection_rows: DashboardConnection[];
    connections_truncated: boolean;
    provider_distribution: {
        provider: string;
        is_demo: boolean;
        origin: string;
        variants: number;
        external_products: number;
        unmapped: number;
    }[];
    attention: {
        id: string;
        count: number;
        title: string;
        description: string;
        url: string;
        severity: string;
    }[];
    activity: {
        kind: string;
        id: string;
        occurred_at: string;
        source: string;
        description: string;
        url: string;
        is_demo: boolean;
    }[];
    filters: {
        providers: { code: string; name: string; is_demo: boolean }[];
        accounts: { id: number; name: string; marketplace: string; is_demo: boolean }[];
    };
    links: { catalog: string; inventory: string };
    availability: {
        has_local_products: boolean;
        has_physical_records: boolean;
        has_external_snapshots: boolean;
        empty_organization: boolean;
    };
}
export const dashboardApi = {
    async overview(params: Params) {
        return (
            await client.get<{ data: DashboardOverview }>('api/v1/dashboard/overview', { params })
        ).data.data;
    },
};
