/** Keyboard/focus behavior for the existing business dialogs; no second modal system. */
export function installDialogAccessibility() {
    let current: HTMLElement | null = null,
        previous: HTMLElement | null = null;
    const visibleDialog = () =>
        Array.from(
            document.querySelectorAll<HTMLElement>(
                '[role="dialog"]:not(.gallery-zoom), [role="alertdialog"]',
            ),
        )
            .filter(
                (el) => el.getBoundingClientRect().width > 0 && !el.closest('[data-tour-engine]'),
            )
            .at(-1) || null;
    const controls = (dialog: HTMLElement) =>
        Array.from(
            dialog.querySelectorAll<HTMLElement>(
                'button:not(:disabled),input:not(:disabled),select:not(:disabled),textarea:not(:disabled),a[href],[tabindex="0"]',
            ),
        ).filter((el) => el.getBoundingClientRect().width > 0);
    new MutationObserver(() => {
        const dialog = visibleDialog();
        if (dialog === current) return;
        if (!dialog) {
            if (previous?.isConnected) previous.focus();
            current = null;
            return;
        }
        previous = document.activeElement as HTMLElement;
        current = dialog;
        dialog.setAttribute('aria-modal', 'true');
        queueMicrotask(() => controls(dialog)[0]?.focus());
    }).observe(document.body, { childList: true, subtree: true });
    document.addEventListener('keydown', (event) => {
        if (!current) return;
        const buttons = controls(current),
            first = buttons[0],
            last = buttons.at(-1);
        if (event.key === 'Tab') {
            if (event.shiftKey && document.activeElement === first) {
                event.preventDefault();
                last?.focus();
            } else if (!event.shiftKey && document.activeElement === last) {
                event.preventDefault();
                first?.focus();
            }
        }
        if (event.key === 'Escape') {
            const cancel = Array.from(
                current.querySelectorAll<HTMLButtonElement>('button:not(:disabled)'),
            ).find((button) => /^(Отмена|Назад|Закрыть)$/.test(button.textContent?.trim() || ''));
            if (cancel) {
                event.preventDefault();
                cancel.click();
            }
        }
    });
}
