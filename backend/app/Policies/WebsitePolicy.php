<?php

namespace App\Policies;

use App\Models\User;
use App\Models\Website;

class WebsitePolicy
{
    public function view(User $user, Website $website): bool
    {
        return $website->project->user_id === $user->id;
    }

    public function update(User $user, Website $website): bool
    {
        return $website->project->user_id === $user->id;
    }

    public function delete(User $user, Website $website): bool
    {
        return $website->project->user_id === $user->id;
    }
}
