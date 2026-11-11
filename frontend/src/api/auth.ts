import { client, csrf } from './client';
import type { User, Organization, Page } from '../types';
export const authApi = {
    async me() {
        return (await client.get<{ data: User }>('api/v1/auth/me')).data.data;
    },
    async login(email: string, password: string) {
        await csrf();
        return (await client.post<{ data: User }>('api/v1/auth/login', { email, password })).data
            .data;
    },
    async logout() {
        await client.post('api/v1/auth/logout');
    },
    async organizations(page = 1) {
        return (
            await client.get<Page<Organization>>('api/v1/organizations', {
                params: { page, per_page: 100 },
            })
        ).data;
    },
    async select(id: number) {
        await client.patch('api/v1/organizations/active', { organization_id: id });
    },
};
