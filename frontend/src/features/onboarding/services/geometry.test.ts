import { describe, expect, it } from 'vitest';
import { tooltipPosition } from './geometry';
import { tours } from '../config/tours';
describe('tour geometry and registry', () => {
    const target = { left: 100, top: 100, right: 200, bottom: 140, width: 100, height: 40 };
    it('places a tooltip outside a highlighted element and inside viewport', () => {
        const p = tooltipPosition(target, 360, 220, { width: 800, height: 600 });
        expect(p.highlight).toBe(true);
        expect(p.top).toBeGreaterThan(target.bottom);
        expect(p.left).toBeGreaterThanOrEqual(12);
    });
    it('uses another side near screen bottom', () => {
        const p = tooltipPosition({ ...target, top: 500, bottom: 540 }, 360, 200, {
            width: 800,
            height: 600,
        });
        expect(p.top + 200).toBeLessThan(500);
    });
    it('falls back without a misleading overlapping highlight when no side can fit', () => {
        expect(
            tooltipPosition(
                { left: 0, top: 0, right: 390, bottom: 600, width: 390, height: 600 },
                360,
                280,
                { width: 390, height: 600 },
            ).highlight,
        ).toBe(false);
    });
    it('keeps tour and step identifiers unique with valid versions and stable selectors', () => {
        expect(new Set(tours.map((t) => t.id)).size).toBe(tours.length);
        for (const tour of tours) {
            expect(tour.version).toBeGreaterThan(0);
            expect(tour.steps.length).toBeLessThanOrEqual(200);
            expect(new Set(tour.steps.map((s) => s.id)).size).toBe(tour.steps.length);
            for (const step of tour.steps) {
                if (step.target) expect(step.target).toMatch(/^\[data-tour="[a-z-]+"\]$/);
            }
        }
    });
});
