import { defineStore } from 'pinia';
import { ref } from 'vue';
import { authApi } from '../api/auth';
import { ApiError, csrf } from '../api/client';
import type { User, Organization } from '../types';
export const useAuth = defineStore('auth', () => {
    const user = ref<User | null>(null),
        ready = ref(false),
        organizations = ref<Organization[]>([]);
    let pending: Promise<void> | null = null;
    async function restore() {
        if (ready.value) return;
        if (!pending)
            pending = (async () => {
                try {
                    user.value = await authApi.me();
                    ready.value = true;
                } catch (error) {
                    if (error instanceof ApiError && error.status === 401) {
                        user.value = null;
                        ready.value = true;
                    } else throw error;
                } finally {
                    pending = null;
                }
            })();
        await pending;
    }
    async function login(email: string, password: string) {
        user.value = await authApi.login(email, password);
        ready.value = true;
    }
    async function logout() {
        await csrf();
        await authApi.logout();
        user.value = null;
        organizations.value = [];
    }
    async function loadOrganizations() {
        const result: Organization[] = [];
        let page = 1;
        let last = 1;
        do {
            const response = await authApi.organizations(page);
            result.push(...response.data);
            last = response.meta.last_page;
            page++;
        } while (page <= last);
        organizations.value = result;
    }
    return { user, ready, organizations, restore, login, logout, loadOrganizations };
});
