import { describe, expect, it } from 'vitest';
import { amount, decimal, purchaseLine } from './procurement';
describe('purchase decimal amounts', () => {
    it('adds decimal line totals exactly', () => {
        expect(decimal(purchaseLine(3, '0.10') + purchaseLine(2, '0.20'))).toBe('0.70');
    });
    it('handles totals above Number safe integer without rounding', () => {
        expect(decimal(purchaseLine(2147483647, '9999999999.99'))).toBe('21474836469978525163.53');
        expect(amount('21474836469978525163.53', 'RUB')).toContain(',53 RUB');
    });
    it('rejects negative, fractional or out of range quantities and prices', () => {
        expect(() => purchaseLine(-1, '1')).toThrow();
        expect(() => purchaseLine(1.5, '1')).toThrow();
        expect(() => purchaseLine(1, '1.001')).toThrow();
        expect(() => purchaseLine(1, '10000000000')).toThrow();
    });
});
