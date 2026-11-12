import { client } from './client';
import type { Page, Params } from '../types';
import type { Run } from './catalog';
export interface Provider {
    code: string;
    name: string;
    connection_type: string;
    capabilities: string[];
    configuration_schema: { key: string; type: string; required: boolean }[];
    credentials_schema: { key: string; type: string; required: boolean }[];
    authorization_notice?: string;
}
export interface Connection {
    id: number;
    name: string;
    marketplace: string;
    status: string;
    is_demo: boolean;
    configuration: Record<string, string>;
    capabilities: string[];
    provider: Provider;
    integration: {
        api_status: string;
        api_checked_at: string | null;
        sync_frequency: 'manual' | 'hourly' | 'daily';
        sync_products: boolean;
        sync_inventory: boolean;
        initial_import_at: string | null;
        last_synced_at: string | null;
        next_sync_at: string | null;
        latest_run: Run | null;
        variants_count: number;
        products_count: number;
        unmapped_count: number;
    };
}
export const connectionsApi = {
    async probe(data: Record<string, unknown>) {
        return (
            await client.post<{ data: { missing_scopes: string[] } }>(
                'api/v1/marketplace-connections/check',
                data,
            )
        ).data.data;
    },
    async providers() {
        return (await client.get<{ data: Provider[] }>('api/v1/marketplace-providers')).data.data;
    },
    async get(id: number) {
        return (await client.get<{ data: Connection }>(`api/v1/marketplace-accounts/${id}`)).data
            .data;
    },
    async save(data: Record<string, unknown>, id?: number) {
        return (
            await (id
                ? client.patch<{ data: Connection }>(`api/v1/marketplace-accounts/${id}`, data)
                : client.post<{ data: Connection }>('api/v1/marketplace-accounts', data))
        ).data.data;
    },
    async check(id: number) {
        return (
            await client.post<{ data: { missing_scopes?: string[] } }>(
                `api/v1/marketplace-accounts/${id}/check`,
            )
        ).data.data;
    },
    async sync(id: number, confirm = false) {
        return (
            await client.post<{ data: Run }>(`api/v1/marketplace-accounts/${id}/sync`, {
                confirm_initial_import: confirm,
            })
        ).data.data;
    },
    async status(id: number) {
        return (
            await client.get<{
                data: {
                    active_run: Run | null;
                    latest_run: Run | null;
                    last_success_at: string | null;
                    next_sync_at: string | null;
                };
            }>(`api/v1/marketplace-accounts/${id}/sync-status`)
        ).data.data;
    },
    async history(id: number, params: Params = {}) {
        return (
            await client.get<Page<Run>>(`api/v1/marketplace-accounts/${id}/sync-runs`, { params })
        ).data;
    },
};

export const syncStatus = (value: string) =>
    (
        ({
            queued: 'В очереди',
            running: 'Выполняется',
            completed: 'Завершён',
            partial: 'Частично завершён',
            failed: 'Ошибка',
        }) as Record<string, string>
    )[value] || value;

export const apiStatus = (value: string) =>
    (
        ({
            connected: 'Проверен',
            unchecked: 'Не проверен',
            missing_permissions: 'Недостаточно прав',
            connection_error: 'Ошибка подключения',
        }) as Record<string, string>
    )[value] || value;
export const syncScope = (value: string) =>
    (({ account: 'Подключение', product: 'Товар', variant: 'Вариант' }) as Record<string, string>)[
        value
    ] || value;
