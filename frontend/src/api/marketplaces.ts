import { client } from './client';
import type { Page, MarketplaceAccount, AccountInput, Params } from '../types';
export const marketplacesApi = {
    async list(params: Params = {}) {
        return (
            await client.get<Page<MarketplaceAccount>>('api/v1/marketplace-accounts', { params })
        ).data;
    },
    async save(data: AccountInput, id?: number) {
        return id
            ? client.patch(`api/v1/marketplace-accounts/${id}`, data)
            : client.post('api/v1/marketplace-accounts', data);
    },
};
