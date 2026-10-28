<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Support\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Models\Account;
use App\Modules\Accounting\Models\JournalEntry;
use App\Modules\Accounting\Services\Ledger;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * القيود اليومية. Entries posted by invoices, payments, expenses and payroll are corrected at their source; manual
 * entries are reversed here. Nothing is edited or deleted.
 */
class JournalController extends Controller
{
    public function index(Request $request): Response
    {
        $from = $request->string('from')->toString() ?: now()->startOfMonth()->toDateString();
        $to = $request->string('to')->toString() ?: today()->toDateString();
        $source = $request->string('source')->toString();

        $entries = JournalEntry::with(['lines.account:id,code,name', 'author:id,name'])
            ->whereBetween('entry_date', [$from, $to])
            ->when($source === 'manual', fn ($q) => $q->whereNull('source_type')->whereNull('reverses_id'))
            ->when($source && $source !== 'manual', fn ($q) => $q->where('source_type', $source))
            ->when($request->string('q')->toString(), fn ($q, $s) => $q->where(fn ($w) => $w->where('description', 'ilike', "%{$s}%")->orWhere('number', 'ilike', "%{$s}%")))
            ->latest('entry_date')->latest('id')->paginate(30)->withQueryString()
            ->through(fn (JournalEntry $e) => [
                'id' => $e->id, 'number' => $e->number, 'date' => $e->entry_date->toDateString(), 'description' => $e->description, 'total' => $e->total,
                'source' => $e->sourceLabel(), 'source_type' => $e->source_type, 'manual' => $e->isManual(), 'reversed' => $e->reversed_at !== null, 'is_reversal' => $e->reverses_id !== null,
                'by' => $e->author?->name,
                'lines' => $e->lines->map(fn ($l) => ['account' => $l->account->code.' · '.$l->account->name, 'debit' => $l->debit, 'credit' => $l->credit, 'memo' => $l->memo]),
            ]);

        return Inertia::render('Finance/Journal', [
            'entries' => $entries,
            'accounts' => Account::postable()->orderBy('code')->get(['id', 'code', 'name', 'type']),
            'sources' => ['manual' => 'قيد يدوي'] + JournalEntry::SOURCES,
            'filters' => ['from' => $from, 'to' => $to, 'source' => $source ?: null, 'q' => $request->string('q')->toString() ?: null],
            'canManage' => $request->user()->hasPermission('accounting.manage'),
        ]);
    }

    public function store(Request $request, Ledger $ledger): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date', 'before_or_equal:today'],
            'description' => ['required', 'string', 'max:255'],
            'lines' => ['required', 'array', 'min:2', 'max:30'],
            'lines.*.account_id' => ['required', 'integer'],
            'lines.*.debit' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.credit' => ['nullable', 'numeric', 'min:0', 'max:999999999'],
            'lines.*.memo' => ['nullable', 'string', 'max:255'],
        ], [], ['description' => 'البيان', 'lines' => 'سطور القيد']);

        $entry = $ledger->post(['date' => CarbonImmutable::parse($data['date']), 'description' => $data['description']], array_map(fn ($l) => [
            'account' => (int) $l['account_id'], 'debit' => $l['debit'] ?? 0, 'credit' => $l['credit'] ?? 0, 'memo' => $l['memo'] ?? null,
        ], $data['lines']));

        return back()->with('success', "رُحّل القيد {$entry->number}.");
    }

    public function reverse(JournalEntry $entry, Ledger $ledger): RedirectResponse
    {
        if (! $entry->isManual()) {
            throw new BusinessRuleException('ledger.source_entry', 'هذا القيد سجّله النظام من مستند (فاتورة، دفعة، مصروف، رواتب)؛ يُصحَّح من المستند نفسه.');
        }
        $mirror = $ledger->reverse($entry, "عكس القيد {$entry->number} — {$entry->description}");

        return back()->with('success', "عُكس القيد بالقيد {$mirror->number}.");
    }
}
