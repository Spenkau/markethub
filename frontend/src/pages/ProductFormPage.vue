<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { productsApi } from '../api/products';
import { ApiError, message, csrf } from '../api/client';
import { fromMinor, toMinor } from '../utils/money';
import PageState from '../components/ui/PageState.vue';
import FieldError from '../components/ui/FieldError.vue';
const route = useRoute(),
    router = useRouter(),
    id = route.params.id ? String(route.params.id) : undefined;
const form = reactive({
        name: '',
        sku: '',
        description: '',
        price: '',
        currency: 'RUB',
        is_active: true,
    }),
    loading = ref(!!id),
    loadError = ref(''),
    error = ref(''),
    busy = ref(false),
    fields = ref<Record<string, string[]>>({});
async function load() {
    if (!id) return;
    loading.value = true;
    loadError.value = '';
    try {
        const p = await productsApi.get(id);
        Object.assign(form, {
            name: p.name,
            sku: p.sku,
            description: p.description || '',
            price: fromMinor(p.price_minor),
            currency: p.currency,
            is_active: p.is_active,
        });
    } catch (e) {
        loadError.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
async function save() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        let minor: number;
        try {
            minor = toMinor(form.price);
        } catch (e) {
            fields.value.price_minor = [message(e)];
            return;
        }
        await csrf();
        await productsApi.save(
            {
                name: form.name,
                sku: form.sku,
                description: form.description || null,
                price_minor: minor,
                currency: form.currency,
                is_active: form.is_active,
            },
            id,
        );
        await router.push('/app/products');
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <RouterLink to="/app/products" class="text-link">← Все товары</RouterLink>
    <div class="page-heading">
        <div>
            <h1>{{ id ? 'Редактирование товара' : 'Новый товар' }}</h1>
            <p>Заполните основные данные карточки.</p>
        </div>
    </div>
    <PageState :loading="loading" :error="loadError" @retry="load">
        <form class="panel edit-form" @submit.prevent="save">
            <p v-if="error" class="alert" role="alert">
                {{ error }}
            </p>
            <label
                >Название<input v-model="form.name" required maxlength="255" /><FieldError
                    :errors="fields.name" /></label
            ><label
                >SKU<input v-model="form.sku" required maxlength="100" /><FieldError
                    :errors="fields.sku"
                /><span class="fine-print">Уникальный артикул в вашей организации.</span></label
            ><label
                >Описание<textarea v-model="form.description" rows="4" maxlength="10000"></textarea
                ><FieldError :errors="fields.description"
            /></label>
            <div class="form-grid">
                <label
                    >Цена<input
                        v-model="form.price"
                        inputmode="decimal"
                        placeholder="0,00"
                        required /><FieldError :errors="fields.price_minor" /></label
                ><label
                    >Валюта<select v-model="form.currency">
                        <option
                            v-for="currency in ['RUB', 'USD', 'EUR', 'BYN', 'KZT', 'CNY', 'GBP']"
                            :key="currency"
                        >
                            {{ currency }}
                        </option></select
                    ><FieldError :errors="fields.currency"
                /></label>
            </div>
            <label class="checkbox-label"
                ><input v-model="form.is_active" type="checkbox" />Активный товар</label
            ><FieldError :errors="fields.is_active" />
            <div class="form-actions">
                <RouterLink to="/app/products" class="secondary">Отмена</RouterLink
                ><button class="primary" :disabled="busy">
                    {{ busy ? 'Сохраняем…' : 'Сохранить товар' }}
                </button>
            </div>
        </form>
    </PageState>
</template>
