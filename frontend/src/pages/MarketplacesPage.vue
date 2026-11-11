<script setup lang="ts">
import { ref, reactive, onMounted } from 'vue';
import { Plus, Pencil, Store } from 'lucide-vue-next';
import { marketplacesApi } from '../api/marketplaces';
import { ApiError, csrf, message } from '../api/client';
import type { Page, MarketplaceAccount, AccountInput } from '../types';
import PageState from '../components/ui/PageState.vue';
import PaginationBar from '../components/ui/PaginationBar.vue';
import FieldError from '../components/ui/FieldError.vue';
const result = ref<Page<MarketplaceAccount>>(),
    page = ref(1),
    loading = ref(true),
    error = ref(''),
    modal = ref(false),
    id = ref<number>(),
    busy = ref(false),
    saveError = ref(''),
    fields = ref<Record<string, string[]>>({});
const form = reactive<AccountInput>({
    name: '',
    marketplace: 'ozon',
    status: 'demo',
    is_demo: true,
});
const statusNames = { demo: 'Демо', active: 'Активно', disabled: 'Отключено' };
async function load() {
    loading.value = true;
    error.value = '';
    try {
        result.value = await marketplacesApi.list({ page: page.value });
    } catch (e) {
        error.value = message(e);
    } finally {
        loading.value = false;
    }
}
onMounted(load);
async function changePage(value: number) {
    page.value = value;
    await load();
}
function edit(account?: MarketplaceAccount) {
    id.value = account?.id;
    Object.assign(
        form,
        account
            ? {
                  name: account.name,
                  marketplace: account.marketplace,
                  status: account.status,
                  is_demo: account.is_demo,
              }
            : { name: '', marketplace: 'ozon', status: 'demo', is_demo: true },
    );
    fields.value = {};
    saveError.value = '';
    modal.value = true;
}
async function save() {
    if (busy.value) return;
    busy.value = true;
    saveError.value = '';
    fields.value = {};
    try {
        await csrf();
        await marketplacesApi.save({ ...form }, id.value);
        modal.value = false;
        await load();
    } catch (e) {
        saveError.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="page-heading">
        <div>
            <p class="eyebrow">КАНАЛЫ ПРОДАЖ</p>
            <h1>Маркетплейсы</h1>
            <p>Подключения вашей организации.</p>
        </div>
        <button class="primary" @click="edit()"><Plus :size="18" />Добавить подключение</button>
    </div>
    <div class="info-banner">
        Подключения работают локально. Обмена данными с Wildberries и Ozon пока нет.
    </div>
    <PageState :loading="loading" :error="error" :empty="!result?.data.length" @retry="load">
        <div class="accounts-grid">
            <article v-for="account in result?.data" :key="account.id" class="panel account-card">
                <div class="account-top">
                    <div class="market-logo" :class="account.marketplace">
                        {{ account.marketplace === 'ozon' ? 'O' : 'WB' }}
                    </div>
                    <span class="badge" :class="account.status === 'active' ? 'success' : ''">{{
                        statusNames[account.status]
                    }}</span>
                </div>
                <h2>{{ account.name }}</h2>
                <p>{{ account.marketplace === 'ozon' ? 'Ozon' : 'Wildberries' }}</p>
                <dl>
                    <dt>Режим</dt>
                    <dd>{{ account.is_demo ? 'Демонстрационный' : 'Локальный' }}</dd>
                    <dt>Размещения</dt>
                    <dd>{{ account.listings_count ?? '—' }}</dd>
                </dl>
                <button class="secondary full" @click="edit(account)">
                    <Pencil :size="15" />Редактировать
                </button>
            </article>
        </div> </PageState
    ><PaginationBar v-if="result" :meta="result.meta" :disabled="loading" @change="changePage" />
    <div v-if="modal" class="modal-backdrop" @keydown.esc="!busy && (modal = false)">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="account-title">
            <Store :size="25" class="blue" />
            <h2 id="account-title">
                {{ id ? 'Редактировать подключение' : 'Новое подключение' }}
            </h2>
            <form @submit.prevent="save">
                <p v-if="saveError" class="alert" role="alert">
                    {{ saveError }}
                </p>
                <label
                    >Название<input
                        v-model="form.name"
                        required
                        maxlength="255"
                        autofocus /><FieldError :errors="fields.name" /></label
                ><label
                    >Маркетплейс<select v-model="form.marketplace">
                        <option value="ozon">Ozon</option>
                        <option value="wildberries">Wildberries</option></select
                    ><FieldError :errors="fields.marketplace" /></label
                ><label
                    >Статус<select v-model="form.status">
                        <option value="demo">Демо</option>
                        <option value="active">Активно</option>
                        <option value="disabled">Отключено</option></select
                    ><FieldError :errors="fields.status" /></label
                ><label class="checkbox-label"
                    ><input v-model="form.is_demo" type="checkbox" />Демонстрационный режим</label
                ><FieldError :errors="fields.is_demo" />
                <p class="fine-print">Статус «Активно» — локальный признак подключения.</p>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="modal = false">
                        Отмена</button
                    ><button class="primary" :disabled="busy">
                        {{ busy ? 'Сохраняем…' : 'Сохранить' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
