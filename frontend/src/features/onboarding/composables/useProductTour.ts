import { defineStore } from 'pinia';
import { computed, nextTick, ref, watch } from 'vue';
import { router } from '../../../router';
import { useAuth } from '../../../stores/auth';
import { ApiError, message, pendingMutations } from '../../../api/client';
import { tours as regularTours } from '../config/tours';
import { demoTours } from '../config/demoTours';
import { tourProgressApi } from '../services/tourProgress';
import type { ProgressAction, TourDefinition, TourPreferences, TourProgress } from '../types';
export function pageIsBusy(): boolean {
    if (pendingMutations.value > 0) return true;
    const visible = (element: Element) => {
        const r = element.getBoundingClientRect();
        return r.width > 0 && r.height > 0;
    };
    return (
        Array.from(
            document.querySelectorAll(
                'form,[role="dialog"],[role="alertdialog"],[data-onboarding-busy="true"]',
            ),
        ).some((element) => !element.closest('[data-tour-engine]') && visible(element)) ||
        !!document.activeElement?.matches('input,textarea,select,[contenteditable="true"]')
    );
}
function findVisible(selector?: string): HTMLElement | undefined {
    if (!selector) return;
    try {
        return Array.from(document.querySelectorAll<HTMLElement>(selector)).find((el) => {
            const r = el.getBoundingClientRect();
            return (
                r.width > 0 &&
                r.height > 0 &&
                r.right > 0 &&
                r.left < window.innerWidth &&
                getComputedStyle(el).visibility !== 'hidden'
            );
        });
    } catch {
        return;
    }
}
export const useProductTour = defineStore('product-tour', () => {
    const auth = useAuth();
    const tours = computed(() => (auth.isDemo ? demoTours : regularTours));
    const records = ref<TourProgress[]>([]),
        preferences = ref<TourPreferences>({ auto_start: true, checklist_collapsed: false });
    const loaded = ref(false),
        activeId = ref(''),
        index = ref(0),
        busy = ref(false),
        preparing = ref(false),
        error = ref(''),
        notice = ref(''),
        offeredId = ref('');
    const target = ref<HTMLElement>(),
        missing = ref(false);
    const active = computed(() => tours.value.find((t) => t.id === activeId.value));
    const step = computed(() => active.value?.steps[index.value]);
    const offered = computed(() => tours.value.find((t) => t.id === offeredId.value));
    let loading: Promise<void> | undefined,
        epoch = 0;
    let preferencesEpoch = 0;
    const owned = new Map<string, string>();
    function progress(tour: TourDefinition) {
        return records.value.find((p) => p.tour_id === tour.id && p.version === tour.version);
    }
    async function load(force = false) {
        if (loading) return loading;
        if (loaded.value && !force) return;
        const userId = auth.user?.id;
        if (!userId) return;
        loading = (async () => {
            const data = await tourProgressApi.load();
            if (auth.user?.id !== userId) return;
            preferences.value = data.preferences;
            records.value = data.progress;
            loaded.value = true;
        })().finally(() => {
            loading = undefined;
        });
        return loading;
    }
    function remember(record: TourProgress) {
        records.value = [
            ...records.value.filter(
                (p) => !(p.tour_id === record.tour_id && p.version === record.version),
            ),
            record,
        ];
    }
    async function save(tour: TourDefinition, action: ProgressAction, at = index.value) {
        const userId = auth.user?.id;
        const record = await tourProgressApi.save(
            tour.id,
            tour.version,
            action,
            tour.steps[at]!.id,
            at,
            tour.trigger?.snoozeHours ?? 24,
        );
        if (auth.user?.id !== userId) throw new Error('Учётная запись изменилась.');
        remember(record);
        return record;
    }
    function closeOwned() {
        for (const close of owned.values()) findVisible(close)?.click();
        owned.clear();
    }
    function dismissOffer() {
        offeredId.value = '';
    }
    function clear() {
        epoch++;
        closeOwned();
        activeId.value = '';
        target.value = undefined;
        preparing.value = false;
        missing.value = false;
    }
    watch(
        () => auth.user?.id,
        () => {
            clear();
            loaded.value = false;
            busy.value = false;
            preferencesEpoch++;
            records.value = [];
            preferences.value = { auto_start: true, checklist_collapsed: false };
            offeredId.value = '';
            loading = undefined;
            notice.value = '';
        },
        { flush: 'sync' },
    );
    async function prepare() {
        const current = ++epoch,
            value = step.value;
        if (!value) return;
        target.value = undefined;
        preparing.value = true;
        missing.value = false;
        try {
            if (!value.reveal) closeOwned();
            if (value.route && router.currentRoute.value.fullPath !== value.route) {
                closeOwned();
                await router.push(value.route);
            }
            await nextTick();
            if (current !== epoch) return;
            let element = findVisible(value.target);
            if (!element && value.reveal) {
                const button = findVisible(value.reveal.target);
                if (button && !pageIsBusy()) {
                    button.click();
                    owned.set(value.reveal.target, value.reveal.close);
                    await nextTick();
                }
            }
            const deadline = Date.now() + 2500;
            while (value.target && !element && Date.now() < deadline && current === epoch) {
                element = findVisible(value.target);
                if (!element) await new Promise((resolve) => setTimeout(resolve, 75));
            }
            if (current !== epoch) return;
            if (element) {
                element.scrollIntoView({ block: 'center', inline: 'nearest', behavior: 'auto' });
                await nextTick();
                let last = element.getBoundingClientRect();
                for (let attempt = 0; attempt < 8 && current === epoch; attempt++) {
                    await new Promise((resolve) => setTimeout(resolve, 50));
                    const next = element.getBoundingClientRect();
                    if (Math.abs(next.left - last.left) + Math.abs(next.top - last.top) < 1) break;
                    last = next;
                }
                if (current === epoch) target.value = element;
            }
            missing.value = !!value.target && !element;
        } catch {
            if (current === epoch) missing.value = true;
        } finally {
            if (current === epoch) preparing.value = false;
        }
    }
    async function start(id: string, restart = false) {
        if (busy.value || active.value) return;
        await auth.restore();
        if (!auth.user) return;
        const startingUser = auth.user.id;
        notice.value = '';
        error.value = '';
        if (pageIsBusy()) {
            notice.value = 'Завершите работу с формой или закройте её, затем запустите экскурсию.';
            return;
        }
        const tour = tours.value.find((t) => t.id === id);
        if (!tour) return;
        busy.value = true;
        try {
            await load(true);
            if (auth.user?.id !== startingUser) return;
            const record = progress(tour);
            const at =
                !restart && record && ['in_progress', 'snoozed'].includes(record.status)
                    ? Math.max(
                          0,
                          tour.steps.findIndex((s) => s.id === record.step_id),
                      )
                    : 0;
            await save(tour, 'start', at);
            offeredId.value = '';
            activeId.value = id;
            index.value = at;
            await prepare();
        } catch (e) {
            notice.value = message(e);
        } finally {
            busy.value = false;
        }
    }
    async function move(delta: number) {
        const tour = active.value;
        if (!tour || busy.value || preparing.value) return;
        const at = index.value + delta;
        if (at < 0 || at >= tour.steps.length) return;
        busy.value = true;
        error.value = '';
        try {
            await save(tour, 'advance', at);
            index.value = at;
            await prepare();
        } catch (e) {
            error.value = message(e);
            if (e instanceof ApiError && e.status === 409) {
                clear();
                notice.value = 'Прогресс изменился. Откройте центр обучения, чтобы продолжить.';
                await load(true).catch(() => undefined);
            }
        } finally {
            busy.value = false;
        }
    }
    async function finish(action: 'complete' | 'skip' | 'snooze') {
        const tour = active.value ?? offered.value;
        if (!tour) return;
        const at = active.value ? index.value : (progress(tour)?.step_index ?? 0);
        if (action === 'complete' && active.value) {
            if (busy.value) return;
            busy.value = true;
            error.value = '';
            try {
                await save(tour, action, at);
                clear();
            } catch (e) {
                error.value = 'Не удалось сохранить завершение: ' + message(e);
            } finally {
                busy.value = false;
            }
            return;
        }
        // Escape always releases the page, even when persistence is unavailable.
        offeredId.value = '';
        clear();
        try {
            await save(tour, action, Math.min(at, tour.steps.length - 1));
        } catch (e) {
            notice.value = 'Не удалось сохранить прогресс: ' + message(e);
        }
    }
    async function setPreferences(value: Partial<TourPreferences>) {
        preferencesEpoch++;
        const userId = auth.user?.id;
        const result = await tourProgressApi.preferences(value);
        if (auth.user?.id !== userId) return;
        preferences.value = result;
        if (!result.auto_start) offeredId.value = '';
    }
    async function offerForRoute() {
        if (
            !auth.user ||
            active.value ||
            offered.value ||
            busy.value ||
            pageIsBusy() ||
            !router.currentRoute.value.path.startsWith('/app')
        )
            return;
        try {
            const preferenceVersion = preferencesEpoch;
            const userId = auth.user?.id;
            await load();
            if (
                auth.user?.id !== userId ||
                !preferences.value.auto_start ||
                pageIsBusy() ||
                active.value
            )
                return;
            const now = Date.now();
            if (
                records.value.some(
                    (p) =>
                        Math.max(
                            Date.parse(p.last_offered_at ?? '') || 0,
                            Date.parse(p.last_started_at ?? '') || 0,
                        ) >
                        now - 15 * 60_000,
                )
            )
                return;
            const route = router.currentRoute.value;
            const candidate = tours.value.find((t) => {
                if (!t.trigger) return false;
                const p = progress(t);
                if (
                    p &&
                    (['completed', 'skipped', 'in_progress'].includes(p.status) ||
                        (p.next_eligible_at && Date.parse(p.next_eligible_at) > now))
                )
                    return false;
                if (t.trigger.type === 'first-login') return true;
                return (
                    route.path === t.trigger.route &&
                    Object.entries(t.trigger.query ?? {}).every(([key, value]) =>
                        value === null ? !route.query[key] : route.query[key] === value,
                    )
                );
            });
            if (!candidate) return;
            await save(candidate, 'offer', progress(candidate)?.step_index ?? 0);
            if (
                preferences.value.auto_start &&
                preferencesEpoch === preferenceVersion &&
                auth.user?.id === userId &&
                !pageIsBusy() &&
                !active.value &&
                router.currentRoute.value.fullPath === route.fullPath
            )
                offeredId.value = candidate.id;
        } catch {
            /* Quietly retry on a later safe visit; never interrupt a business operation. */
        }
    }
    return {
        records,
        preferences,
        loaded,
        active,
        index,
        step,
        busy,
        preparing,
        target,
        missing,
        error,
        notice,
        offered,
        progress,
        load,
        start,
        move,
        finish,
        setPreferences,
        offerForRoute,
        clear,
        dismissOffer,
    };
});
