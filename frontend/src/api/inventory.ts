import { client } from './client';
import type { Page, Params, Product } from '../types';
export interface Warehouse {
    id: number;
    name: string;
    code: string;
    type: 'own' | 'marketplace';
    is_active: boolean;
    stocks_count?: number;
    created_at: string;
    updated_at: string;
}
export type WarehouseInput = Pick<Warehouse, 'name' | 'code' | 'type' | 'is_active'>;
export interface WarehouseStock {
    id: number;
    warehouse_id: number;
    product_id: number;
    quantity: number;
    updated_at: string;
    warehouse: Warehouse;
    product: Pick<Product, 'id' | 'name' | 'sku'>;
}
export type OperationType = 'receipt' | 'write_off' | 'transfer' | 'inventory';
export interface StockMovement {
    id: number;
    operation_id: string;
    type: OperationType;
    warehouse_id: number;
    product_id: number;
    document?: { type: 'goods_receipt'; id: number; purchase_order_id: number } | null;
    quantity_delta: number;
    quantity_before: number;
    quantity_after: number;
    comment: string | null;
    occurred_at: string;
    warehouse: Warehouse;
    product: Pick<Product, 'id' | 'name' | 'sku'>;
    user: { id: number; name: string };
}
export interface InventorySummary {
    warehouses: number;
    active_warehouses: number;
    positions: number;
    products: number;
    units: number;
    zero_positions: number;
    low_positions: number;
}
export interface OperationInput {
    operation_id: string;
    type: OperationType;
    warehouse_id: number;
    product_id: number;
    destination_warehouse_id?: number;
    quantity: number;
    comment: string;
}
export const operationNames: Record<OperationType, string> = {
    receipt: 'Поступление',
    write_off: 'Списание',
    transfer: 'Перемещение',
    inventory: 'Инвентаризация',
};
export const inventoryApi = {
    async warehouses(params: Params = {}) {
        return (await client.get<Page<Warehouse>>('api/v1/warehouses', { params })).data;
    },
    async warehouse(id: number) {
        return (await client.get<{ data: Warehouse }>(`api/v1/warehouses/${id}`)).data.data;
    },
    async saveWarehouse(data: WarehouseInput, id?: number) {
        return id
            ? client.patch<{ data: Warehouse }>(`api/v1/warehouses/${id}`, data)
            : client.post<{ data: Warehouse }>('api/v1/warehouses', data);
    },
    async stocks(params: Params = {}) {
        return (await client.get<Page<WarehouseStock>>('api/v1/warehouse-stocks', { params })).data;
    },
    async movements(params: Params = {}) {
        return (await client.get<Page<StockMovement>>('api/v1/stock-movements', { params })).data;
    },
    async summary(params: Params = {}) {
        return (
            await client.get<{ data: InventorySummary }>('api/v1/warehouse-stocks/summary', {
                params,
            })
        ).data.data;
    },
    async operate(data: OperationInput) {
        return (
            await client.post<{
                data: { operation_id: string; movements: StockMovement[] };
                meta: { replayed: boolean };
            }>('api/v1/stock-operations', data)
        ).data;
    },
};
