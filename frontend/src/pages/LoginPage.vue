<script setup lang="ts">
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuth } from '../stores/auth';
import { ApiError, message } from '../api/client';
import FieldError from '../components/ui/FieldError.vue';
const email = ref(''),
    password = ref(''),
    busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
const auth = useAuth(),
    route = useRoute(),
    router = useRouter();
async function submit() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        await auth.login(email.value, password.value);
        const target = String(route.query.redirect || '/app');
        await router.replace(
            target.startsWith('/app') && !target.startsWith('//') ? target : '/app',
        );
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
        password.value = '';
    }
}
</script>
<template>
    <main class="auth-page">
        <RouterLink to="/" class="brand"
            ><span class="brand-symbol">M</span>MarketHub<span class="brand-dot"
                >.</span
            ></RouterLink
        >
        <section class="auth-card">
            <p class="eyebrow">С ВОЗВРАЩЕНИЕМ</p>
            <h1>Вход в кабинет</h1>
            <p class="muted">Продолжите работу с вашим каталогом.</p>
            <form @submit.prevent="submit">
                <p v-if="error" class="alert" role="alert">
                    {{ error }}
                </p>
                <label
                    >Email<input
                        v-model="email"
                        type="email"
                        autocomplete="username"
                        required
                        maxlength="255" /><FieldError :errors="fields.email" /></label
                ><label
                    >Пароль<input
                        v-model="password"
                        type="password"
                        autocomplete="current-password"
                        required
                        maxlength="1024" /><FieldError :errors="fields.password" /></label
                ><button class="primary full" :disabled="busy">
                    {{ busy ? 'Входим…' : 'Войти в кабинет' }}
                </button>
            </form>
            <p class="fine-print">
                Доступ предоставляет администратор вашей организации. Публичная регистрация пока
                недоступна.
            </p>
        </section>
        <RouterLink to="/" class="text-link">← На главную</RouterLink>
    </main>
</template>
