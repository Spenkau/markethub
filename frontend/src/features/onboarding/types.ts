export type TourStatus = 'not_started' | 'in_progress' | 'completed' | 'skipped' | 'snoozed';
export interface TourStep {
    id: string;
    target?: string;
    title: string;
    description: string;
    placement?: 'top' | 'bottom' | 'left' | 'right' | 'auto';
    route?: string;
    reveal?: { target: string; close: string };
}
export interface TourDefinition {
    id: string;
    title: string;
    description: string;
    version: number;
    trigger?: {
        type: 'first-login' | 'first-visit';
        route?: string;
        query?: Record<string, string | null>;
        snoozeHours?: number;
    };
    steps: TourStep[];
}
export interface TourProgress {
    tour_id: string;
    version: number;
    status: TourStatus;
    step_id: string;
    step_index: number;
    last_started_at: string | null;
    last_offered_at: string | null;
    completed_at: string | null;
    next_eligible_at: string | null;
}
export interface TourPreferences {
    auto_start: boolean;
    checklist_collapsed: boolean;
}
export type ProgressAction = 'offer' | 'start' | 'advance' | 'complete' | 'skip' | 'snooze';
