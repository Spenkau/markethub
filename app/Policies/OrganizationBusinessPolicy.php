<?php

namespace App\Policies;

use App\Models\User;
use App\Support\OrganizationContext;
use Illuminate\Database\Eloquent\Model;

class OrganizationBusinessPolicy
{
    public function viewAny(User $user): bool
    {
        return true;
    }

    public function create(User $user): bool
    {
        return OrganizationContext::id($user) !== null;
    }

    public function view(User $user, Model $record): bool
    {
        return $this->create($user) && (int) $record->organization_id === OrganizationContext::id($user);
    }

    public function update(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }

    public function delete(User $user, Model $record): bool
    {
        return $this->view($user, $record);
    }
}
