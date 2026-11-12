export interface Rect {
    top: number;
    left: number;
    right: number;
    bottom: number;
    width: number;
    height: number;
}
export function tooltipPosition(
    target: Rect | undefined,
    width: number,
    height: number,
    viewport: { width: number; height: number },
    placement = 'auto',
) {
    const margin = 12,
        gap = 14;
    const clamp = (v: number, max: number) => Math.max(margin, Math.min(v, max - margin));
    const centered = {
        left: Math.max(margin, (viewport.width - width) / 2),
        top: Math.max(margin, (viewport.height - height) / 2),
        highlight: false,
    };
    if (!target) return centered;
    const choices = [...new Set([placement, 'bottom', 'top', 'right', 'left'])];
    for (const side of choices) {
        if (side === 'bottom' && viewport.height - target.bottom >= height + gap + margin)
            return {
                left: clamp(target.left, viewport.width - width),
                top: target.bottom + gap,
                highlight: true,
            };
        if (side === 'top' && target.top >= height + gap + margin)
            return {
                left: clamp(target.left, viewport.width - width),
                top: target.top - gap - height,
                highlight: true,
            };
        if (side === 'right' && viewport.width - target.right >= width + gap + margin)
            return {
                left: target.right + gap,
                top: clamp(target.top, viewport.height - height),
                highlight: true,
            };
        if (side === 'left' && target.left >= width + gap + margin)
            return {
                left: target.left - gap - width,
                top: clamp(target.top, viewport.height - height),
                highlight: true,
            };
    }
    return centered;
}
