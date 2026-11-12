export function shortDate(value?: string) {
    if (!value) return '—';
    const date = new Date(value);
    return Number.isNaN(date.getTime())
        ? '—'
        : date.toLocaleDateString('ru-RU', { day: 'numeric', month: 'short', year: 'numeric' });
}
