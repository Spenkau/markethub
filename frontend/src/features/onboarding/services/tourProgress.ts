import { client } from '../../../api/client';
import type { ProgressAction, TourPreferences, TourProgress } from '../types';
export const tourProgressApi = {
    async load() {
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
        return (
            await client.patch<{ data: TourPreferences }>('api/v1/onboarding/preferences', data)
        ).data.data;
    },
};
