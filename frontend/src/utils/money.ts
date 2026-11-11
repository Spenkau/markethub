export function toMinor(value: string): number {
    const normalized = value.trim().replace(',', '.');
    if (!/^\d+(\.\d{1,2})?$/.test(normalized))
        throw new Error('Введите неотрицательную цену с точностью до двух знаков.');
    const [whole = '0', fraction = ''] = normalized.split('.');
    const minor = BigInt(whole) * 100n + BigInt(fraction.padEnd(2, '0'));
    if (minor > 999999999999n) throw new Error('Цена превышает допустимый предел.');
    return Number(minor);
}
export function fromMinor(value: number): string {
    const minor = BigInt(value);
    return `${minor / 100n}.${(minor % 100n).toString().padStart(2, '0')}`;
}
export function money(value: number, currency: string): string {
    const [whole, fraction] = fromMinor(value).split('.');
    return `${BigInt(whole!).toLocaleString('ru-RU')},${fraction} ${currency}`;
}
