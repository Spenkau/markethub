import { client } from './client';
import type { Page, Product, ProductInput, Params } from '../types';
export const productsApi = {
    async list(params: Params = {}, signal?: AbortSignal) {
        return (await client.get<Page<Product>>('api/v1/products', { params, signal })).data;
    },
    async get(id: string) {
        return (await client.get<{ data: Product }>(`api/v1/products/${id}`)).data.data;
    },
    async save(data: ProductInput, id?: string) {
        return id
            ? client.patch(`api/v1/products/${id}`, data)
            : client.post('api/v1/products', data);
    },
    async remove(id: number) {
        await client.delete(`api/v1/products/${id}`);
    },
};
