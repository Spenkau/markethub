<?php

namespace App\Policies;

use App\Models\User;
use App\Support\OrganizationContext;

class InventoryPolicy extends OrganizationBusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return OrganizationContext::id($user) !== null;
    }
}
