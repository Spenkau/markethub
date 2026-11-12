<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { operationKey } from '../../utils/operationKey';
import { inventoryApi, operationNames, type OperationType } from '../../api/inventory';
import { ApiError, message } from '../../api/client';
import FieldError from '../ui/FieldError.vue';
import ResourcePicker from './ResourcePicker.vue';
const props = defineProps<{ type: OperationType }>();
const emit = defineEmits<{ close: []; saved: [] }>();
const form = reactive({
    warehouse: null as number | null,
    destination: null as number | null,
    product: null as number | null,
    quantity: props.type === 'inventory' ? 0 : 1,
    comment: '',
});
const busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
let operationId = operationKey();
// Preserve the key on network errors; changed input represents a new operation.
watch(
    form,
    () => {
        operationId = operationKey();
    },
    { flush: 'sync' },
);
async function save() {
    if (busy.value) return;
    if (!form.warehouse || !form.product || (props.type === 'transfer' && !form.destination)) {
        error.value = 'Выберите товар и склады для операции.';
        return;
    }
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        await inventoryApi.operate({
            operation_id: operationId,
            type: props.type,
            warehouse_id: form.warehouse,
            product_id: form.product,
            destination_warehouse_id: props.type === 'transfer' ? form.destination! : undefined,
            quantity: Number(form.quantity),
            comment: form.comment,
        });
        emit('saved');
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
        <section
            class="modal inventory-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="operation-title"
        >
            <h2 id="operation-title">{{ operationNames[type] }}</h2>
            <p class="fine-print">
                {{
                    type === 'inventory'
                        ? 'Укажите фактически пересчитанное количество. Система сохранит разницу как движение.'
                        : type === 'transfer'
                          ? 'Товар будет списан и принят одной операцией. Общее количество не изменится.'
                          : 'Изменение сохранится в истории с вашим именем и датой.'
                }}
            </p>
            <form @submit.prevent="save">
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <fieldset :disabled="busy">
                    <ResourcePicker
                        v-model="form.product"
                        kind="products"
                        label="Товар"
                    /><FieldError :errors="fields.product_id" />
                    <div class="form-grid">
                        <div>
                            <ResourcePicker
                                v-model="form.warehouse"
                                kind="warehouses"
                                :label="type === 'transfer' ? 'Исходный склад' : 'Склад'"
                                active-only
                            /><FieldError :errors="fields.warehouse_id" />
                        </div>
                        <div v-if="type === 'transfer'">
                            <ResourcePicker
                                v-model="form.destination"
                                kind="warehouses"
                                label="Целевой склад"
                                active-only
                            /><FieldError :errors="fields.destination_warehouse_id" />
                        </div>
                    </div>
                    <label
                        >{{ type === 'inventory' ? 'Фактический остаток' : 'Количество'
                        }}<input
                            v-model="form.quantity"
                            type="number"
                            :min="type === 'inventory' ? 0 : 1"
                            max="2147483647"
                            step="1"
                            required /><FieldError :errors="fields.quantity" /></label
                    ><label
                        >Комментарий<textarea
                            v-model="form.comment"
                            rows="2"
                            maxlength="2000"
                            placeholder="Основание операции" /><FieldError :errors="fields.comment"
                    /></label>
                </fieldset>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">
                        Отмена</button
                    ><button class="primary" :disabled="busy">
                        {{ busy ? 'Выполняем…' : 'Провести операцию' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
