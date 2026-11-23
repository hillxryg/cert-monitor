<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin \App\Models\Enrollment */
class EnrollmentResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $campaign = $this->campaign;

        return [
            'enrollment_id' => $this->id,
            'joined_at' => $this->joined_at?->toIso8601String(),
            'campaign' => [
                'id' => $campaign->id,
                'title' => $campaign->title,
                'platform_name' => $campaign->platform_name,
                'target_url_pattern' => $campaign->target_url_pattern,
                'rules' => [
                    'max_playback_speed' => (float) $campaign->max_playback_speed,
                    'allow_muted_playback' => (bool) $campaign->allow_muted_playback,
                    'max_skip_seconds' => (int) $campaign->max_skip_seconds,
                    'required_completion_percent' => (int) $campaign->required_completion_percent,
                    'min_integrity_score' => (int) $campaign->min_integrity_score,
                    'heartbeat_interval_seconds' => (int) config('integrity.heartbeat_interval'),
                ],
            ],
        ];
    }
}
