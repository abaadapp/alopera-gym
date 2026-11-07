<?php

namespace App\Modules\Messaging\Services;

use App\Models\User;
use App\Modules\Messaging\Models\Conversation;
use App\Modules\Messaging\Models\Message;
use Carbon\CarbonImmutable;

/**
 * WhatsApp figures for a period (club days, inclusive): conversations started, open now and closed; messages in and
 * out by where they came from; who holds which chats; how long a first answer took; how the automatic notices fared;
 * and the days one by one. Sample (demo) conversations are left out.
 */
final class WhatsAppReports
{
    /** @return array<string, mixed> */
    public function figures(CarbonImmutable $from, CarbonImmutable $to): array
    {
        $start = $from->startOfDay();
        $end = $to->endOfDay();
        $real = fn () => Conversation::where('is_demo', false);
        $messages = fn () => Message::whereNotNull('conversation_id')->whereHas('conversation', fn ($q) => $q->where('is_demo', false))
            ->whereRaw('coalesce(messages.wa_timestamp, messages.created_at) between ? and ?', [$start, $end]);

        $bySource = $messages()->selectRaw("direction, coalesce(source, case when direction = 'inbound' then 'customer' when sent_by is null then 'automation' else 'platform' end) as src, count(*) as n")
            ->groupBy('direction', 'src')->get();

        $first = $real()->whereBetween('first_inbound_at', [$start, $end])->whereNotNull('first_response_at')
            ->selectRaw('count(*) as n, avg(extract(epoch from (first_response_at - first_inbound_at))) as seconds')->first();

        $automatic = Message::where('direction', 'outbound')->where('type', 'template')->whereNull('sent_by')->whereNull('campaign_id')
            ->whereBetween('created_at', [$start, $end])->selectRaw('status, count(*) as n')->groupBy('status')->pluck('n', 'status');

        $perStaff = $real()->whereNotNull('assigned_to')->whereIn('status', [Conversation::NEW, Conversation::OPEN, Conversation::PENDING])
            ->selectRaw('assigned_to, count(*) as n')->groupBy('assigned_to')->pluck('n', 'assigned_to');
        $replies = $messages()->where('direction', 'outbound')->whereNotNull('sent_by')->selectRaw('sent_by, count(*) as n')->groupBy('sent_by')->pluck('n', 'sent_by');
        $names = User::whereIn('id', $perStaff->keys()->merge($replies->keys())->unique())->pluck('name', 'id');

        $days = $messages()->selectRaw("to_char(coalesce(messages.wa_timestamp, messages.created_at), 'YYYY-MM-DD') as day, direction, count(*) as n")
            ->groupBy('day', 'direction')->get()->groupBy('day');

        return [
            'conversations' => [
                'new' => $real()->whereBetween('created_at', [$start, $end])->count(),
                'open_now' => $real()->whereIn('status', [Conversation::NEW, Conversation::OPEN, Conversation::PENDING])->count(),
                'closed' => $real()->where('status', Conversation::CLOSED)->whereBetween('closed_at', [$start, $end])->count(),
                'by_status' => collect(Conversation::STATUSES)->map(fn ($label, $key) => ['key' => $key, 'label' => $label, 'count' => $real()->where('status', $key)->count()])->values(),
                'unassigned' => $real()->whereNull('assigned_to')->whereIn('status', [Conversation::NEW, Conversation::OPEN, Conversation::PENDING])->count(),
            ],
            'messages' => [
                'inbound' => (int) $bySource->where('direction', 'inbound')->sum('n'),
                'outbound' => (int) $bySource->where('direction', 'outbound')->sum('n'),
                'by_source' => $bySource->map(fn ($r) => ['direction' => $r->direction, 'source' => $r->src, 'count' => (int) $r->n])->values(),
            ],
            'first_response' => ['conversations' => (int) ($first->n ?? 0), 'seconds' => $first?->seconds !== null ? (int) round((float) $first->seconds) : null],
            'automatic' => [
                'succeeded' => (int) ($automatic['sent'] ?? 0) + (int) ($automatic['delivered'] ?? 0) + (int) ($automatic['read'] ?? 0),
                'failed' => (int) ($automatic['failed'] ?? 0),
                'skipped' => (int) ($automatic['skipped'] ?? 0),
                'waiting' => (int) ($automatic['queued'] ?? 0) + (int) ($automatic['scheduled'] ?? 0),
            ],
            'staff' => $names->map(fn ($name, $id) => ['id' => $id, 'name' => $name, 'open' => (int) ($perStaff[$id] ?? 0), 'replies' => (int) ($replies[$id] ?? 0)])
                ->sortByDesc('open')->values(),
            'days' => $days->map(fn ($rows, $day) => ['day' => $day, 'inbound' => (int) $rows->where('direction', 'inbound')->sum('n'), 'outbound' => (int) $rows->where('direction', 'outbound')->sum('n')])
                ->sortBy('day')->values(),
        ];
    }
}
