import { client } from './client';
import type { Page, Stock, Params } from '../types';
export const stocksApi = {
    async list(params: Params = {}) {
        return (await client.get<Page<Stock>>('api/v1/stocks', { params })).data;
    },
};
