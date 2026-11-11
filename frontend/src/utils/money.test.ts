import { describe, it, expect } from 'vitest';
import { toMinor, fromMinor, money } from './money';
describe('денежные значения', () => {
    it('преобразует десятичную строку без float', () => {
        expect(toMinor('0,29')).toBe(29);
        expect(toMinor('1990.00')).toBe(199000);
        expect(toMinor('9999999999.99')).toBe(999999999999);
    });
    it('отклоняет отрицательные, дробные копейки и переполнение', () => {
        for (const value of ['-1', '1.001', '1e3', '', '10000000000'])
            expect(() => toMinor(value)).toThrow();
    });
    it('сохраняет копейки при отображении', () => {
        expect(fromMinor(29)).toBe('0.29');
        expect(money(199000, 'RUB')).toContain('990,00 RUB');
    });
});
