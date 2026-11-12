import { client } from './client';
import type { Page, Params, Product } from '../types';
import type { Warehouse } from './inventory';
export interface Supplier {
    id: number;
    name: string;
    contact_name: string | null;
    phone: string | null;
    email: string | null;
    inn: string | null;
    comment: string | null;
    is_active: boolean;
    purchase_orders_count?: number;
    created_at: string;
    updated_at: string;
}
export type SupplierInput = Pick<
    Supplier,
    'name' | 'contact_name' | 'phone' | 'email' | 'inn' | 'comment' | 'is_active'
>;
export type PurchaseStatus = 'draft' | 'ordered' | 'partially_received' | 'received' | 'cancelled';
export type ReceiptStatus = 'draft' | 'confirmed' | 'cancelled';
export const purchaseStatusNames: Record<PurchaseStatus, string> = {
    draft: 'Черновик',
    ordered: 'Заказана',
    partially_received: 'Частично получена',
    received: 'Получена',
    cancelled: 'Отменена',
};
export const receiptStatusNames: Record<ReceiptStatus, string> = {
    draft: 'Черновик',
    confirmed: 'Проведена',
    cancelled: 'Отменена',
};
export interface PurchaseItem {
    id: number;
    product_id: number;
    quantity: number;
    received_quantity: number;
    remaining_quantity: number;
    unit_price: string;
    line_total: string;
    product: Pick<Product, 'id' | 'name' | 'sku'>;
}
export interface PurchaseOrder {
    id: number;
    request_id: string;
    supplier_id: number;
    warehouse_id: number;
    status: PurchaseStatus;
    currency: string;
    total_amount: string;
    comment: string | null;
    supplier: Supplier;
    warehouse: Warehouse;
    ordered_quantity?: number;
    received_quantity?: number;
    receipts_count?: number;
    items?: PurchaseItem[];
    creator?: { id: number; name: string };
    created_at: string;
    updated_at: string;
}
export interface ReceiptItem {
    id: number;
    purchase_order_item_id: number;
    product_id: number;
    quantity: number;
    stock_movement_id: number | null;
    stock_operation_id: string | null;
    product: Pick<Product, 'id' | 'name' | 'sku'>;
}
export interface GoodsReceipt {
    id: number;
    request_id: string;
    purchase_order_id: number;
    status: ReceiptStatus;
    comment: string | null;
    confirmed_at: string | null;
    purchase_order: PurchaseOrder;
    items?: ReceiptItem[];
    total_quantity?: number;
    creator: { id: number; name: string };
    confirmer: { id: number; name: string } | null;
    created_at: string;
    updated_at: string;
}
export interface PurchaseInput {
    request_id?: string;
    supplier_id: number;
    warehouse_id: number;
    currency: string;
    comment: string;
    items: { product_id: number; quantity: number; unit_price: string }[];
}
export interface ReceiptInput {
    request_id?: string;
    purchase_order_id?: number;
    comment: string;
    items: { purchase_order_item_id: number; quantity: number }[];
}
export const procurementApi = {
    async suppliers(params: Params = {}) {
        return (await client.get<Page<Supplier>>('api/v1/suppliers', { params })).data;
    },
    async supplier(id: number) {
        return (await client.get<{ data: Supplier }>(`api/v1/suppliers/${id}`)).data.data;
    },
    async saveSupplier(data: SupplierInput, id?: number) {
        return (
            await (id
                ? client.patch<{ data: Supplier }>(`api/v1/suppliers/${id}`, data)
                : client.post<{ data: Supplier }>('api/v1/suppliers', data))
        ).data.data;
    },
    async deleteSupplier(id: number) {
        await client.delete(`api/v1/suppliers/${id}`);
    },
    async orders(params: Params = {}) {
        return (await client.get<Page<PurchaseOrder>>('api/v1/purchase-orders', { params })).data;
    },
    async order(id: number) {
        return (await client.get<{ data: PurchaseOrder }>(`api/v1/purchase-orders/${id}`)).data
            .data;
    },
    async saveOrder(data: PurchaseInput, id?: number) {
        return (
            await (id
                ? client.patch<{ data: PurchaseOrder }>(`api/v1/purchase-orders/${id}`, data)
                : client.post<{ data: PurchaseOrder }>('api/v1/purchase-orders', data))
        ).data.data;
    },
    async transitionOrder(id: number, action: 'order' | 'cancel') {
        return (
            await client.post<{ data: PurchaseOrder }>(`api/v1/purchase-orders/${id}/${action}`)
        ).data.data;
    },
    async receipts(params: Params = {}) {
        return (await client.get<Page<GoodsReceipt>>('api/v1/goods-receipts', { params })).data;
    },
    async receipt(id: number) {
        return (await client.get<{ data: GoodsReceipt }>(`api/v1/goods-receipts/${id}`)).data.data;
    },
    async saveReceipt(data: ReceiptInput, id?: number) {
        return (
            await (id
                ? client.patch<{ data: GoodsReceipt }>(`api/v1/goods-receipts/${id}`, data)
                : client.post<{ data: GoodsReceipt }>('api/v1/goods-receipts', data))
        ).data.data;
    },
    async confirmReceipt(id: number) {
        return (
            await client.post<{ data: GoodsReceipt; meta: { replayed: boolean } }>(
                `api/v1/goods-receipts/${id}/confirm`,
            )
        ).data;
    },
    async cancelReceipt(id: number) {
        return (await client.post<{ data: GoodsReceipt }>(`api/v1/goods-receipts/${id}/cancel`))
            .data.data;
    },
};
