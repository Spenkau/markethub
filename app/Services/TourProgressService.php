<?php

namespace App\Services;

use App\Models\User;
use App\Models\UserTourProgress;
use Illuminate\Support\Facades\DB;

class TourProgressService
{
    public function update(User $actor, string $tour, array $data): UserTourProgress
    {
        abort_unless(auth()->id() === $actor->id, 403);

        return DB::transaction(function () use ($actor, $tour, $data) {
            $user = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $progress = UserTourProgress::firstOrNew(['user_id' => $user->id, 'tour_id' => $tour, 'version' => $data['version']]);
            $action = $data['action'];
            if ($action === 'complete' && $progress->status === 'completed') {
                return $progress;
            }
            abort_if($progress->status === 'completed' && in_array($action, ['skip', 'snooze'], true), 409, 'Экскурсия уже завершена на другом устройстве.');
            if ($action === 'offer' || ($action === 'start' && ($data['automatic'] ?? false))) {
                abort_unless($user->tour_auto_start, 409, 'Автоматические подсказки отключены.');
                abort_if(in_array($progress->status, ['completed', 'skipped', 'in_progress'], true) || $progress->next_eligible_at?->isFuture(), 409, 'Сейчас экскурсию нельзя предлагать автоматически.');
            }
            if ($action === 'offer') {
                $recent = UserTourProgress::where('user_id', $user->id)->where(fn ($query) => $query->where('last_offered_at', '>', now()->subMinutes(15)->toIso8601String())->orWhere('last_started_at', '>', now()->subMinutes(15)->toIso8601String()))->exists();
                abort_if($recent, 409, 'Недавно уже была предложена экскурсия.');
                $progress->status = $progress->status ?? 'not_started';
                $progress->last_offered_at = now();
                $progress->next_eligible_at = now()->addDay();
            } elseif ($action === 'start') {
                $progress->status = 'in_progress';
                $progress->last_started_at = now();
                $progress->completed_at = null;
            } elseif ($action === 'advance' || $action === 'complete') {
                abort_unless($progress->exists && $progress->status === 'in_progress', 409, 'Экскурсия не запущена или уже завершена на другом устройстве.');
                if ($action === 'complete') {
                    $progress->status = 'completed';
                    $progress->completed_at = now();
                }
            } elseif ($action === 'skip') {
                $progress->status = 'skipped';
                $progress->completed_at = null;
            } else {
                $progress->status = 'snoozed';
                $progress->completed_at = null;
                $progress->next_eligible_at = now()->addHours($data['snooze_hours'] ?? 24);
            }
            $progress->step_id = $data['step_id'];
            $progress->step_index = $data['step_index'];
            $progress->save();

            return $progress;
        });
    }
}
