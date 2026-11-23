<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\HeartbeatRequest;
use App\Models\Enrollment;
use App\Services\HeartbeatProcessor;
use Illuminate\Http\JsonResponse;

class HeartbeatController extends Controller
{
    public function store(HeartbeatRequest $request, HeartbeatProcessor $processor): JsonResponse
    {
        $enrollment = Enrollment::query()
            ->with('campaign')
            ->where('campaign_id', $request->integer('campaign_id'))
            ->where('student_id', $request->user()->id)
            ->firstOrFail();

        if (! $enrollment->campaign->isOpen()) {
            return response()->json(['message' => 'This campaign is not currently accepting progress.'], 403);
        }

        return response()->json($processor->process($enrollment, $request->validated()));
    }
}
