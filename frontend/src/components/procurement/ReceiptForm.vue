<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import { procurementApi, type PurchaseOrder, type GoodsReceipt } from '../../api/procurement';
import { ApiError, message } from '../../api/client';
import { operationKey } from '../../utils/operationKey';
const props = defineProps<{ order: PurchaseOrder; receipt?: GoodsReceipt }>();
const emit = defineEmits<{ close: []; saved: [receipt: GoodsReceipt] }>();
const form = reactive({
    comment: props.receipt?.comment ?? '',
    items: (props.order.items ?? [])
        .filter((i) => i.remaining_quantity > 0)
        .map((i) => ({
            id: i.id,
            name: i.product.name,
            sku: i.product.sku,
            ordered: i.quantity,
            received: i.received_quantity,
            remaining: i.remaining_quantity,
            quantity: props.receipt
                ? (props.receipt.items?.find((r) => r.purchase_order_item_id === i.id)?.quantity ??
                  0)
                : i.remaining_quantity,
        })),
});
const busy = ref(false),
    error = ref(''),
    fields = ref<Record<string, string[]>>({});
let requestId = operationKey();
watch(
    form,
    () => {
        requestId = operationKey();
    },
    { flush: 'sync' },
);
async function save() {
    if (busy.value) return;
    const items = form.items
        .filter((i) => Number(i.quantity) > 0)
        .map((i) => ({ purchase_order_item_id: i.id, quantity: Number(i.quantity) }));
    if (!items.length) {
        error.value = 'Укажите количество хотя бы для одного товара.';
        return;
    }
    busy.value = true;
    error.value = '';
    fields.value = {};
    try {
        const receipt = await procurementApi.saveReceipt(
            {
                request_id: props.receipt ? undefined : requestId,
                purchase_order_id: props.receipt ? undefined : props.order.id,
                comment: form.comment,
                items,
            },
            props.receipt?.id,
        );
        emit('saved', receipt);
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
            class="modal purchase-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="receipt-title"
        >
            <h2 id="receipt-title">
                {{
                    receipt
                        ? `Редактировать приёмку #${receipt.id}`
                        : `Приёмка закупки #${order.id}`
                }}
            </h2>
            <p class="fine-print">
                {{ order.warehouse.name }} · {{ order.supplier.name }}. Нулевые строки не
                включаются. Остатки изменятся только после подтверждения документа.
            </p>
            <form @submit.prevent="save">
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <ul v-if="Object.keys(fields).length" class="validation-list">
                    <li v-for="(errors, field) in fields" :key="field">{{ errors.join(' ') }}</li>
                </ul>
                <fieldset :disabled="busy">
                    <button
                        type="button"
                        class="secondary"
                        @click="form.items.forEach((i) => (i.quantity = i.remaining))"
                    >
                        Заполнить весь остаток
                    </button>
                    <div class="receipt-edit-lines">
                        <div v-for="item in form.items" :key="item.id" class="receipt-edit-line">
                            <div>
                                <strong>{{ item.name }}</strong
                                ><span class="table-subtitle"
                                    >{{ item.sku }} · Заказано {{ item.ordered }}, получено
                                    {{ item.received }}, осталось {{ item.remaining }}</span
                                >
                            </div>
                            <label
                                >Принимаем<input
                                    v-model="item.quantity"
                                    type="number"
                                    min="0"
                                    :max="item.remaining"
                                    step="1"
                                    required
                                    :aria-label="`Принимаем: ${item.name}`"
                            /></label>
                        </div>
                    </div>
                    <p v-if="!form.items.length" class="fine-print">
                        У закупки нет неполученных товаров. Отмените устаревший черновик приёмки.
                    </p>
                    <label
                        >Комментарий<textarea v-model="form.comment" maxlength="5000" rows="2" />
                    </label>
                </fieldset>
                <div class="form-actions">
                    <button type="button" class="secondary" :disabled="busy" @click="emit('close')">
                        Отмена</button
                    ><button class="primary" :disabled="busy || !form.items.length">
                        {{
                            busy
                                ? 'Сохраняем…'
                                : receipt
                                  ? 'Сохранить черновик приёмки'
                                  : 'Создать документ приёмки'
                        }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
