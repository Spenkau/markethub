import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { router } from './router';
import { useAuth } from './stores/auth';
import './assets/main.css';
const app = createApp(App);
app.use(createPinia());
app.use(router);
window.addEventListener('session-expired', () => {
    const auth = useAuth();
    auth.user = null;
    if (router.currentRoute.value.meta.requiresAuth)
        void router.replace({
            path: '/login',
            query: { redirect: router.currentRoute.value.fullPath },
        });
});
app.mount('#app');
