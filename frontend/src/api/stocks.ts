import { client } from './client';
export interface ExternalLocation {
    id: number;
    marketplace_account_id: number;
    name: string;
    is_active: boolean;
    warehouse_id: number | null;
}
import type { Page, Stock, Params } from '../types';
export const stocksApi = {
    async locations(account: number) {
        return (
            await client.get<{ data: ExternalLocation[] }>(
                `api/v1/marketplace-accounts/${account}/locations`,
            )
        ).data.data;
    },
    async mapLocation(location: number, warehouse: number | null) {
        return client.patch(`api/v1/marketplace-locations/${location}/mapping`, {
            warehouse_id: warehouse,
        });
    },
    async list(params: Params = {}) {
        return (await client.get<Page<Stock>>('api/v1/stocks', { params })).data;
    },
};
