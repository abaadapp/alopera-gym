<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\MembershipPlan;
use App\Modules\Gym\Models\Program;
use App\Modules\Messaging\Models\Campaign;
use App\Modules\Messaging\Models\Message;
use App\Modules\Messaging\Models\MessageTemplate;
use App\Modules\Messaging\Services\AudienceBuilder;
use App\Modules\Messaging\Services\CampaignService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CampaignController extends Controller
{
    public function __construct(private readonly CampaignService $campaigns, private readonly AudienceBuilder $audiences)
    {
    }

    public function index(): Response
    {
        return Inertia::render('Messaging/Campaigns/Index', [
            'campaigns' => Campaign::with('template:id,name')->latest()->paginate(20)->through(fn (Campaign $c) => $c->only(
                'id', 'name', 'status', 'audience', 'recipients_count', 'sent_count', 'delivered_count', 'read_count', 'failed_count'
            ) + ['template' => $c->template?->name, 'scheduled_at' => $c->scheduled_at?->format('Y-m-d H:i'), 'created_at' => $c->created_at->format('Y-m-d')]),
            'templates' => MessageTemplate::where('is_active', true)->get(['id', 'name', 'key', 'status', 'category', 'body']),
            'segments' => AudienceBuilder::SEGMENTS,
            'plans' => MembershipPlan::orderBy('sort')->get(['id', 'name']),
            'programs' => Program::where('is_active', true)->get(['id', 'name']),
        ]);
    }

    public function audience(Request $request): JsonResponse
    {
        return response()->json(['count' => $this->audiences->query($this->audienceInput($request))->count()]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'message_template_id' => ['required', TenantRule::exists('message_templates')],
            'scheduled_at' => ['nullable', 'date', 'after:now'],
        ]);

        $campaign = Campaign::create($data + ['audience' => $this->audienceInput($request), 'status' => Campaign::DRAFT, 'created_by' => $request->user()->id]);

        return redirect()->route('club.campaigns.show', $campaign)->with('success', 'تم إنشاء الحملة. راجعي الجمهور ثم أطلقيها.');
    }

    public function show(Campaign $campaign): Response
    {
        $campaign->load('template');

        return Inertia::render('Messaging/Campaigns/Show', [
            'campaign' => $campaign->only('id', 'name', 'status', 'audience', 'recipients_count', 'sent_count', 'delivered_count', 'read_count', 'failed_count') + [
                'template' => $campaign->template?->only('id', 'name', 'body', 'status'),
                'scheduled_at' => $campaign->scheduled_at?->format('Y-m-d H:i'), 'started_at' => $campaign->started_at?->format('Y-m-d H:i'),
                'completed_at' => $campaign->completed_at?->format('Y-m-d H:i'),
                'audience_size' => in_array($campaign->status, [Campaign::DRAFT, Campaign::SCHEDULED], true) ? $this->audiences->query($campaign->audience ?? [])->count() : null,
            ],
            'messages' => Message::with('member:id,full_name')->where('campaign_id', $campaign->id)->latest('id')->limit(200)->get()
                ->map(fn ($m) => ['id' => $m->id, 'member' => $m->member?->full_name, 'status' => $m->status, 'error' => $m->error_code, 'sent_at' => $m->sent_at?->format('Y-m-d H:i')]),
        ]);
    }

    public function launch(Campaign $campaign): RedirectResponse
    {
        $campaign->load('template');
        $this->campaigns->launch($campaign);

        return back()->with('success', $campaign->status === Campaign::SCHEDULED ? 'تمت جدولة الحملة.' : 'بدأ إرسال الحملة.');
    }

    public function cancel(Campaign $campaign): RedirectResponse
    {
        $this->campaigns->cancel($campaign);

        return back()->with('success', 'تم إيقاف الحملة.');
    }

    /** @return array<string, mixed> */
    private function audienceInput(Request $request): array
    {
        $data = $request->validate([
            'segment' => ['required', 'in:'.implode(',', array_keys(AudienceBuilder::SEGMENTS))],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
            'plan_ids' => ['nullable', 'array'],
            'plan_ids.*' => [TenantRule::exists('gym_membership_plans')],
            'program_ids' => ['nullable', 'array'],
            'program_ids.*' => [TenantRule::exists('gym_programs')],
        ]);

        return [
            'segment' => $data['segment'],
            'days' => $data['days'] ?? null,
            'plan_ids' => array_map('intval', $data['plan_ids'] ?? []),
            'program_ids' => array_map('intval', $data['program_ids'] ?? []),
        ];
    }
}
