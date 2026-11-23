<?php

namespace App\Policies;

use App\Models\Campaign;
use App\Models\User;

class CampaignPolicy
{
    /** An instructor may view/manage only the campaigns they own. */
    public function manage(User $user, Campaign $campaign): bool
    {
        return $user->id === $campaign->instructor_id;
    }
}
