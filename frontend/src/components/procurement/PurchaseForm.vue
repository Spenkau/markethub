<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import { Plus, Trash2 } from 'lucide-vue-next';
import { procurementApi, type PurchaseOrder, type Supplier } from '../../api/procurement';
import { productsApi } from '../../api/products';
import { ApiError, message } from '../../api/client';
import { amount, cents, decimal, purchaseLine } from '../../utils/procurement';
import { operationKey } from '../../utils/operationKey';
import ResourcePicker from '../inventory/ResourcePicker.vue';
import type { Product } from '../../types';
import type { Warehouse } from '../../api/inventory';
const props = defineProps<{
    order?: PurchaseOrder;
    initialSupplier?: Supplier;
    initialWarehouse?: Warehouse;
    initialProduct?: Product;
}>();
const emit = defineEmits<{ close: []; saved: [order: PurchaseOrder] }>();
const form = reactive({
    supplier: props.order?.supplier_id ?? props.initialSupplier?.id ?? (null as number | null),
    warehouse: props.order?.warehouse_id ?? props.initialWarehouse?.id ?? (null as number | null),
    currency: props.order?.currency ?? 'RUB',
    comment: props.order?.comment ?? '',
    items:
        props.order?.items?.map((i) => ({
            product_id: i.product_id,
            name: i.product.name,
            sku: i.product.sku,
            quantity: i.quantity,
            unit_price: i.unit_price,
        })) ??
        (props.initialProduct
            ? [
                  {
                      product_id: props.initialProduct.id,
                      name: props.initialProduct.name,
                      sku: props.initialProduct.sku,
                      quantity: 1,
                      unit_price: '0.00',
                  },
              ]
            : []),
});
const product = ref<number | null>(null),
    busy = ref(false),
    adding = ref(false),
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
const total = computed(() => {
    try {
        return amount(
            decimal(
                form.items.reduce((sum, i) => sum + purchaseLine(i.quantity, i.unit_price), 0n),
            ),
            form.currency,
        );
    } catch {
        return '—';
    }
});
function line(quantity: number, price: string) {
    try {
        return amount(decimal(purchaseLine(quantity, price)), form.currency);
    } catch {
        return '—';
    }
}
async function add() {
    if (!product.value || adding.value) return;
    if (form.items.some((i) => i.product_id === product.value)) {
        error.value = 'Товар уже добавлен в закупку.';
        return;
    }
    if (form.items.length >= 100) {
        error.value = 'В одной закупке допускается до 100 товаров.';
        return;
    }
    adding.value = true;
    error.value = '';
    try {
        const p = await productsApi.get(String(product.value));
        form.items.push({
            product_id: p.id,
            name: p.name,
            sku: p.sku,
            quantity: 1,
            unit_price: '0.00',
        });
    } catch (e) {
        error.value = message(e);
    } finally {
        adding.value = false;
    }
}
async function save() {
    if (busy.value || adding.value) return;
    error.value = '';
    fields.value = {};
    if (!form.supplier || !form.warehouse || !form.items.length) {
        error.value = 'Выберите поставщика, склад и добавьте товары.';
        return;
    }
    try {
        form.items.forEach((i) => purchaseLine(i.quantity, i.unit_price));
    } catch (e) {
        error.value = message(e);
        return;
    }
    busy.value = true;
    try {
        const saved = await procurementApi.saveOrder(
            {
                request_id: props.order ? undefined : requestId,
                supplier_id: form.supplier,
                warehouse_id: form.warehouse,
                currency: form.currency,
                comment: form.comment,
                items: form.items.map((i) => ({
                    product_id: i.product_id,
                    quantity: Number(i.quantity),
                    unit_price: decimal(cents(i.unit_price)),
                })),
            },
            props.order?.id,
        );
        emit('saved', saved);
    } catch (e) {
        error.value = message(e);
        if (e instanceof ApiError) fields.value = e.fields;
    } finally {
        busy.value = false;
    }
}
</script>
<template>
    <div class="modal-backdrop" @keydown.esc="!busy && !adding && emit('close')">
        <section
            class="modal purchase-modal"
            role="dialog"
            aria-modal="true"
            aria-labelledby="purchase-title"
        >
            <h2 id="purchase-title">
                {{ order ? `Редактировать закупку #${order.id}` : 'Новая закупка' }}
            </h2>
            <p class="fine-print">
                Сохраните черновик, проверьте цены и состав, затем переведите закупку в статус
                «Заказана».
            </p>
            <form @submit.prevent="save">
                <p v-if="error" class="alert" role="alert">{{ error }}</p>
                <ul v-if="Object.keys(fields).length" class="validation-list">
                    <li v-for="(errors, field) in fields" :key="field">{{ errors.join(' ') }}</li>
                </ul>
                <fieldset :disabled="busy || adding">
                    <div class="form-grid" data-tour="purchase-parties">
                        <ResourcePicker
                            v-model="form.supplier"
                            kind="suppliers"
                            label="Поставщик"
                            active-only
                            :selected-option="
                                order
                                    ? { id: order.supplier.id, label: order.supplier.name }
                                    : initialSupplier
                                      ? { id: initialSupplier.id, label: initialSupplier.name }
                                      : undefined
                            "
                        /><ResourcePicker
                            v-model="form.warehouse"
                            kind="warehouses"
                            label="Склад назначения"
                            active-only
                            :selected-option="
                                order
                                    ? { id: order.warehouse.id, label: order.warehouse.name }
                                    : initialWarehouse
                                      ? { id: initialWarehouse.id, label: initialWarehouse.name }
                                      : undefined
                            "
                        />
                    </div>
                    <div class="purchase-add-row" data-tour="purchase-items">
                        <ResourcePicker
                            v-model="product"
                            kind="products"
                            label="Товар для закупки"
                        /><button
                            type="button"
                            class="secondary"
                            :disabled="!product || adding"
                            @click="add"
                        >
                            <Plus :size="15" />Добавить строку
                        </button>
                    </div>
                    <div class="purchase-lines">
                        <div
                            v-for="(item, index) in form.items"
                            :key="item.product_id"
                            class="purchase-edit-line"
                        >
                            <div>
                                <strong>{{ item.name }}</strong
                                ><span class="table-subtitle mono">{{ item.sku }}</span>
                            </div>
                            <label
                                >Количество<input
                                    v-model="item.quantity"
                                    type="number"
                                    min="1"
                                    max="2147483647"
                                    step="1"
                                    required
                                    :aria-label="`Количество: ${item.name}`" /></label
                            ><label
                                >Закупочная цена<input
                                    v-model="item.unit_price"
                                    inputmode="decimal"
                                    required
                                    :aria-label="`Закупочная цена: ${item.name}`" /></label
                            ><strong class="nowrap">{{
                                line(item.quantity, item.unit_price)
                            }}</strong
                            ><button
                                type="button"
                                class="icon-button danger-text"
                                :aria-label="`Удалить строку: ${item.name}`"
                                @click="form.items.splice(index, 1)"
                            >
                                <Trash2 :size="16" />
                            </button>
                        </div>
                    </div>
                    <div class="purchase-total">
                        <label
                            >Валюта<select v-model="form.currency">
                                <option
                                    v-for="currency in [
                                        'RUB',
                                        'USD',
                                        'EUR',
                                        'BYN',
                                        'KZT',
                                        'CNY',
                                        'GBP',
                                    ]"
                                    :key="currency"
                                >
                                    {{ currency }}
                                </option>
                            </select></label
                        >
                        <div>
                            <span>Итого</span><strong>{{ total }}</strong>
                        </div>
                    </div>
                    <label
                        >Комментарий<textarea v-model="form.comment" maxlength="5000" rows="2" />
                    </label>
                </fieldset>
                <div class="form-actions">
                    <button
                        type="button"
                        class="secondary"
                        :disabled="busy || adding"
                        data-tour="close-purchase-form"
                        @click="emit('close')"
                    >
                        Отмена</button
                    ><button class="primary" :disabled="busy || adding">
                        {{ busy ? 'Сохраняем…' : 'Сохранить черновик' }}
                    </button>
                </div>
            </form>
        </section>
    </div>
</template>
