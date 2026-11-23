<?php

namespace App\Http\Controllers\Web;

use App\Http\Controllers\Controller;
use App\Http\Requests\Web\CampaignRequest;
use App\Models\Campaign;
use App\Models\Enrollment;
use App\Models\IntegrityLog;
use App\Services\CampaignReport;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignReport $report)
    {
    }

    public function index(Request $request): View
    {
        return view('dashboard', [
            'campaigns' => $this->report->dashboardCampaigns($request->user()),
        ]);
    }

    public function create(): View
    {
        // Seed the form with the same defaults the database uses.
        $campaign = new Campaign([
            'required_completion_percent' => 90,
            'min_integrity_score' => 70,
            'max_playback_speed' => 1.25,
            'max_skip_seconds' => 10,
            'allow_muted_playback' => false,
        ]);

        return view('campaigns.create', compact('campaign'));
    }

    public function store(CampaignRequest $request): RedirectResponse
    {
        $campaign = Campaign::create($request->validated() + [
            'instructor_id' => $request->user()->id,
            'status' => 'active',
        ]);

        return redirect()
            ->route('campaigns.show', $campaign)
            ->with('status', 'Campaign created. Share the join code with your students.');
    }

    public function show(Request $request, Campaign $campaign): View
    {
        Gate::authorize('manage', $campaign);

        $filter = in_array($request->query('status'), CampaignReport::STATUSES, true)
            ? $request->query('status')
            : null;

        $enrollments = $this->report->roster($campaign, $filter)->paginate(25)->withQueryString();
        $enrollments->getCollection()->each(
            fn (Enrollment $e) => $e->setAttribute('audit_status', $this->report->statusFor($e, $campaign))
        );

        return view('campaigns.show', [
            'campaign' => $campaign,
            'summary' => $this->report->summary($campaign),
            'enrollments' => $enrollments,
            'filter' => $filter,
        ]);
    }

    public function edit(Campaign $campaign): View
    {
        Gate::authorize('manage', $campaign);

        return view('campaigns.edit', compact('campaign'));
    }

    public function update(CampaignRequest $request, Campaign $campaign): RedirectResponse
    {
        Gate::authorize('manage', $campaign);

        $campaign->update($request->validated());

        return redirect()
            ->route('campaigns.show', $campaign)
            ->with('status', 'Campaign updated. New rules apply to heartbeats received from now on.');
    }

    public function toggle(Campaign $campaign): RedirectResponse
    {
        Gate::authorize('manage', $campaign);

        $campaign->update(['status' => $campaign->status === 'active' ? 'closed' : 'active']);

        return back()->with(
            'status',
            $campaign->status === 'active'
                ? 'Campaign reopened. Students can join and report progress again.'
                : 'Campaign closed. New joins and progress are no longer accepted.'
        );
    }

    public function audit(Campaign $campaign, Enrollment $enrollment): View
    {
        Gate::authorize('manage', $campaign);
        abort_unless($enrollment->campaign_id === $campaign->id, 404);

        $enrollment->load('student:id,name,email');
        $enrollment->setAttribute('audit_status', $this->report->statusFor($enrollment, $campaign));

        $logs = IntegrityLog::query()
            ->where('enrollment_id', $enrollment->id)
            ->with('lessonProgress:id,lesson_key,lesson_title')
            ->orderByDesc('occurred_at')
            ->orderByDesc('id')
            ->paginate(30);

        $eventTotals = IntegrityLog::query()
            ->where('enrollment_id', $enrollment->id)
            ->selectRaw('event_type, count(*) as occurrences, sum(points_deducted) as points')
            ->groupBy('event_type')
            ->orderByDesc('points')
            ->get();

        return view('campaigns.audit', [
            'campaign' => $campaign,
            'enrollment' => $enrollment,
            'lessons' => $this->report->lessonBreakdown($enrollment),
            'logs' => $logs,
            'eventTotals' => $eventTotals,
        ]);
    }
}
