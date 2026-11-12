<script setup lang="ts">
import { reactive, ref } from 'vue';
import { inventoryApi, type Warehouse, type WarehouseInput } from '../../api/inventory';
import { ApiError, message } from '../../api/client';
import FieldError from '../ui/FieldError.vue';
const props = defineProps<{ warehouse?: Warehouse }>();
const emit = defineEmits<{ close: []; saved: [warehouse: Warehouse] }>();
const form = reactive<WarehouseInput>(
    props.warehouse
        ? {
              name: props.warehouse.name,
              code: props.warehouse.code,
              type: props.warehouse.type,
              is_active: props.warehouse.is_active,
          }
        : { name: '', code: '', type: 'own', is_active: true },
);
const busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
async function save() {
    if (busy.value) return;
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const response = await inventoryApi.saveWarehouse({ ...form }, props.warehouse?.id);
        emit('saved', response.data.data);
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="modal-backdrop" @keydown.esc="!busy && emit('close')">
        <section class="modal" role="dialog" aria-modal="true" aria-labelledby="warehouse-title">
            <h2 id="warehouse-title">{{ warehouse ? 'Редактировать склад' : 'Новый склад' }}</h2>
            <p class="fine-print">
                Отключённый склад остаётся в истории, новые операции на нём недоступны.
            </p>
            <form @submit.prevent="save">
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <fieldset :disabled="busy">
                    <label
                        >Название склада<input
                            v-model="form.name"
                            required
                            maxlength="255"
                            autofocus /><FieldError :errors="fields.name" /></label
                    ><label
                        >Код склада<input
                            v-model="form.code"
                            aria-label="Код склада"
                            required
                            maxlength="50"
                            placeholder="MAIN-01"
                            pattern="[A-Za-z0-9_-]+" /><span class="fine-print"
                            >Латинские буквы, цифры, дефис и подчёркивание</span
                        ><FieldError :errors="fields.code" /></label
                    ><label
                        >Тип склада<select v-model="form.type">
                            <option value="own">Собственный</option>
                            <option value="marketplace">Склад маркетплейса</option></select
                        ><FieldError :errors="fields.type" /></label
                    ><label class="checkbox-label"
                        ><input v-model="form.is_active" type="checkbox" />Склад активен</label
                    >
                </fieldset>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">
                        Отмена</button
                    ><button class="primary" :disabled="busy">
                        {{ busy ? 'Сохраняем…' : 'Сохранить склад' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
