<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\JoinCampaignRequest;
use App\Http\Resources\EnrollmentResource;
use App\Models\Campaign;
use App\Models\Enrollment;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class EnrollmentController extends Controller
{
    /**
     * The student's enrollments in campaigns that are currently open, with each campaign's
     * latest details and rules. The extension uses this to stay in sync after an instructor
     * edits a campaign. A campaign that was closed (or has left its date window) is simply
     * absent, which tells the extension to stop monitoring it.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $enrollments = Enrollment::query()
            ->where('student_id', $request->user()->id)
            ->whereHas('campaign', fn($campaigns) => $campaigns->open())
            ->with('campaign')
            ->orderBy('id')
            ->get();

        return EnrollmentResource::collection($enrollments);
    }

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
