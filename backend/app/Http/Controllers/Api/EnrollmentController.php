<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinCampaignRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Campaign;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;

class EnrollmentController extends Controller
{
    public function join(JoinCampaignRequest $request): JsonResponse
    {
        $user = $request->user();

        $campaign = Campaign::where('join_code', $request->validated('join_code'))->first();

        if (! $campaign || ! $campaign->isOpen()) {
            return response()->json(['message' => 'Invalid or inactive join code.'], 404);
        }

        if ($campaign->instructor_id === $user->id) {
            return response()->json(['message' => 'Instructors cannot enroll in their own campaign.'], 403);
        }

        // Idempotent: re-joining returns the existing enrollment (and the rules) with 200.
        $enrollment = Enrollment::createOrFirst(
            ['campaign_id' => $campaign->id, 'student_id' => $user->id],
            ['joined_at' => now()],
        );
        $enrollment->setRelation('campaign', $campaign);

        return (new EnrollmentResource($enrollment))
            ->response()
            ->setStatusCode($enrollment->wasRecentlyCreated ? 201 : 200);
    }
}
