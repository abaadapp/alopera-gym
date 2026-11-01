<?php

namespace App\Modules\Accounting\Http\Controllers;

use App\Core\Support\SubmittedOnce;
use App\Http\Controllers\Controller;
use App\Modules\Accounting\Services\AccountingPeriods;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * الفترات المحاسبية: each month and year with its status. Closing takes accounting.close and shows the month's checks
 * first; reopening takes accounting.reopen and a reason. AccountingPeriods keeps every rule.
 */
class PeriodController extends Controller
{
    private const MONTH = ['required', 'string', 'regex:/^\d{4}-(0[1-9]|1[0-2])$/'];

    private const REASON = ['required', 'string', 'min:10', 'max:500'];

    public function __construct(private readonly AccountingPeriods $periods) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        $check = $request->string('check')->toString();

        return Inertia::render('Finance/Periods', [
            'months' => $this->periods->months(),
            'years' => $this->periods->years(),
            'lockedThrough' => $this->periods->lockedThrough()?->toDateString(),
            'canClose' => $user->can('accounting.close'),
            'canReopen' => $user->can('accounting.reopen'),
            // Asked for by the closing dialog only (a partial reload with ?check=YYYY-MM).
            'checks' => Inertia::optional(fn () => $user->can('accounting.close') && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $check) ? $this->periods->checks($check) : null),
        ]);
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => self::MONTH, 'client_key' => SubmittedOnce::RULE], [], ['month' => 'الشهر']);
        $closed = SubmittedOnce::run($data['client_key'] ?? null, 'period-close', fn () => $this->periods->closeThrough($data['month'], $request->user()));

        return back()->with('success', 'أُقفلت الفترة: '.implode('، ', $closed).'.');
    }

    public function reopen(Request $request): RedirectResponse
    {
        $data = $request->validate(['month' => self::MONTH, 'reason' => self::REASON], [], ['month' => 'الشهر', 'reason' => 'سبب إعادة الفتح']);
        $row = $this->periods->reopen($data['month'], $data['reason'], $request->user());

        return back()->with('success', 'أُعيد فتح '.$row->starts_on->locale('ar')->translatedFormat('F Y').'. سُجّل السبب في سجل التدقيق.');
    }

    public function closeYear(Request $request): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100']], [], ['year' => 'السنة']);
        $this->periods->closeYear((int) $data['year'], $request->user());

        return back()->with('success', 'أُقفلت سنة '.$data['year'].'.');
    }

    public function reopenYear(Request $request): RedirectResponse
    {
        $data = $request->validate(['year' => ['required', 'integer', 'min:2000', 'max:2100'], 'reason' => self::REASON], [], ['year' => 'السنة', 'reason' => 'سبب إعادة الفتح']);
        $this->periods->reopenYear((int) $data['year'], $data['reason'], $request->user());

        return back()->with('success', 'أُعيد فتح سنة '.$data['year'].'. أشهرها ما زالت مقفلة حتى يُعاد فتحها.');
    }
}
