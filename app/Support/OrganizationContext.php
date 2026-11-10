<?php

namespace App\Support;

use App\Models\User;

class OrganizationContext
{
    public static function id(?User $user = null): ?int
    {
        $user ??= auth()->user();
        $id = $user?->active_organization_id && $user->organizations()->whereKey($user->active_organization_id)->exists()
            ? $user->active_organization_id
            : $user?->organizations()->orderBy('organizations.id')->value('organizations.id');

        return $id === null ? null : (int) $id;
    }
}
