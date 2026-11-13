import { client } from '../../../api/client';
import type { ProgressAction, TourPreferences, TourProgress } from '../types';
import { useAuth } from '../../../stores/auth';
type DemoProgress = { preferences: TourPreferences; progress: TourProgress[] };
function demoState(): DemoProgress {
    try {
        const saved = sessionStorage.getItem('markethub-demo-tours');
        if (saved) return JSON.parse(saved) as DemoProgress;
    } catch {
        /* Private browser storage may be unavailable. */
    }
    return { preferences: { auto_start: true, checklist_collapsed: false }, progress: [] };
}
function saveDemo(state: DemoProgress) {
    try {
        sessionStorage.setItem('markethub-demo-tours', JSON.stringify(state));
    } catch {
        /* Session-only best effort. */
    }
}
export const tourProgressApi = {
    async load() {
        if (useAuth().isDemo) return demoState();
        return (
            await client.get<{ data: { preferences: TourPreferences; progress: TourProgress[] } }>(
                'api/v1/onboarding',
            )
        ).data.data;
    },
    async save(
        tour: string,
        version: number,
        action: ProgressAction,
        step_id: string,
        step_index: number,
        snooze_hours = 24,
    ) {
        if (useAuth().isDemo) {
            const state = demoState(),
                now = new Date().toISOString();
            const previous = state.progress.find(
                (p) => p.tour_id === tour && p.version === version,
            );
            const progress: TourProgress = {
                tour_id: tour,
                version,
                step_id,
                step_index,
                status:
                    action === 'complete'
                        ? 'completed'
                        : action === 'skip'
                          ? 'skipped'
                          : action === 'snooze'
                            ? 'snoozed'
                            : action === 'offer'
                              ? previous?.status || 'not_started'
                              : 'in_progress',
                last_started_at: action === 'start' ? now : previous?.last_started_at || null,
                last_offered_at: action === 'offer' ? now : previous?.last_offered_at || null,
                completed_at: action === 'complete' ? now : previous?.completed_at || null,
                next_eligible_at:
                    action === 'snooze'
                        ? new Date(Date.now() + snooze_hours * 3600000).toISOString()
                        : null,
            };
            state.progress = state.progress.filter((p) => p.tour_id !== tour);
            state.progress.push(progress);
            saveDemo(state);
            return progress;
        }
        return (
            await client.patch<{ data: TourProgress }>(`api/v1/onboarding/tours/${tour}`, {
                version,
                action,
                step_id,
                step_index,
                snooze_hours,
            })
        ).data.data;
    },
    async preferences(data: Partial<TourPreferences>) {
        if (useAuth().isDemo) {
            const state = demoState();
            state.preferences = { ...state.preferences, ...data };
            saveDemo(state);
            return state.preferences;
        }
        return (
            await client.patch<{ data: TourPreferences }>('api/v1/onboarding/preferences', data)
        ).data.data;
    },
};
