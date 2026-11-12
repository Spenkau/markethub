export function cents(value: string): bigint {
    const normalized = value.trim().replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(normalized))
        throw new Error('Введите сумму с точностью до двух знаков.');
    const [whole = '0', fraction = ''] = normalized.split('.');
    return BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
}
export function decimal(value: bigint): string {
    return `${value / 100n}.${(value % 100n).toString().padStart(2, '0')}`;
}
export function amount(value: string, currency: string): string {
    const minor = cents(value);
    return `${(minor / 100n).toLocaleString('ru-RU')},${(minor % 100n).toString().padStart(2, '0')} ${currency}`;
}
export function purchaseLine(quantity: number | string, price: string): bigint {
    if (!/^\d+$/.test(String(quantity)) || BigInt(quantity) < 1n || BigInt(quantity) > 2147483647n)
        throw new Error('Введите положительное целое количество.');
    const unit = cents(price);
    if (unit > 999999999999n) throw new Error('Закупочная цена превышает допустимый предел.');
    return BigInt(quantity) * unit;
}
