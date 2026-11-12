import axios from 'axios';
import { ref } from 'vue';
export const pendingMutations = ref(0);
const mutates = (method?: string) =>
    ['post', 'patch', 'put', 'delete'].includes((method ?? '').toLowerCase());
export class ApiError extends Error {
    constructor(
        message: string,
        public status = 0,
        public fields: Record<string, string[]> = {},
    ) {
        super(message);
    }
}
export const client = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '/',
    withCredentials: true,
    withXSRFToken: true,
    headers: { Accept: 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
});
client.interceptors.request.use((config) => {
    if (mutates(config.method)) pendingMutations.value++;
    return config;
});
client.interceptors.response.use(
    (response) => {
        if (mutates(response.config.method))
            pendingMutations.value = Math.max(0, pendingMutations.value - 1);
        return response;
    },
    async (error: unknown) => {
        if (!axios.isAxiosError(error)) return Promise.reject(error);
        if (mutates(error.config?.method))
            pendingMutations.value = Math.max(0, pendingMutations.value - 1);
        const status = error.response?.status ?? 0;
        const body = error.response?.data as
            { message?: string; errors?: Record<string, string[]> } | undefined;
        if (status === 401) window.dispatchEvent(new Event('session-expired'));
        if (status === 419 && error.config?.url !== 'sanctum/csrf-cookie') {
            // Refresh CSRF, but let the user explicitly retry the rejected mutation.
            await csrf().catch(() => undefined);
        }
        const messages: Record<number, string> = {
            401: 'Сессия завершена. Войдите снова.',
            403: 'Нет доступа к организации или действию.',
            404: 'Запись не найдена.',
            409: body?.message || 'Операция конфликтует со связанными данными.',
            419: 'Срок CSRF-cookie истёк. Повторите действие.',
            422: 'Проверьте заполненные поля.',
            429: 'Слишком много попыток. Попробуйте через минуту.',
        };
        return Promise.reject(
            new ApiError(
                messages[status] || 'Не удалось связаться с сервером. Попробуйте ещё раз.',
                status,
                body?.errors,
            ),
        );
    },
);
export const csrf = () => client.get('sanctum/csrf-cookie');
export function message(error: unknown) {
    return error instanceof Error ? error.message : 'Произошла ошибка';
}
