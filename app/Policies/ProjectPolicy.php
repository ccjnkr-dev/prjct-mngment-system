<?php

namespace App\Policies;

use App\Models\Project;
use App\Models\User;

class ProjectPolicy
{
    public function view(User $user, Project $project): bool
    {
        return $user->active && ($user->role === 'admin' || $project->members()->where('users.id', $user->id)->exists());
    }

    public function update(User $user, Project $project): bool
    {
        return $this->view($user, $project);
    }

    public function manage(User $user, Project $project): bool
    {
        return $user->active && $user->role === 'admin';
    }
}
