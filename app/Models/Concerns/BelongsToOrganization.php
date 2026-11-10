<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Validation\ValidationException;

trait BelongsToOrganization
{
    protected static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query): void {
            $id = OrganizationContext::id();
            $id === null ? $query->whereRaw('1 = 0') : $query->where($query->qualifyColumn('organization_id'), $id);
        });
        static::saving(function ($record): void {
            if (auth()->check()) {
                $id = OrganizationContext::id();
                if ($id === null || ($record->organization_id && (int) $record->organization_id !== $id)) {
                    throw ValidationException::withMessages(['organization_id' => 'Нет доступа к организации.']);
                }
                $record->organization_id = $id;
            }
            if (! $record->organization_id || ($record->exists && $record->isDirty('organization_id'))) {
                throw ValidationException::withMessages(['organization_id' => 'Организация обязательна и не может быть изменена.']);
            }
            $record->validateBusinessRules();
        });
        static::deleting(function ($record): void {
            if (auth()->check() && (int) $record->organization_id !== OrganizationContext::id()) {
                throw ValidationException::withMessages(['organization_id' => 'Нет доступа к организации.']);
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
