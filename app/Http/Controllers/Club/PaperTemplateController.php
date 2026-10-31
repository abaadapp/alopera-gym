<?php

namespace App\Http\Controllers\Club;

use App\Core\Audit\AuditLogger;
use App\Core\Papers\Papers;
use App\Core\Papers\PaperTypes;
use App\Core\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * قوالب الأوراق (papers.manage): a card per paper, and an editor whose preview is drawn on the server by the same
 * builder and view that print the real document — never a picture or a second copy of the layout in the browser.
 * Settings are the club's own; another club's papers are never read or written here.
 */
class PaperTemplateController extends Controller
{
    public function __construct(private readonly TenantContext $tenants) {}

    public function index(): Response
    {
        return Inertia::render('Club/Settings/Papers', ['papers' => PaperTypes::cards($this->tenants->require())]);
    }

    public function edit(string $type): Response
    {
        abort_unless(PaperTypes::exists($type), 404);
        $tenant = $this->tenants->require();

        return Inertia::render('Club/Settings/PaperEditor', [
            'paper' => PaperTypes::describe($tenant, $type),
            'others' => array_values(array_filter(PaperTypes::cards($tenant), fn ($c) => $c['key'] !== $type)),
            'brand' => ['logo' => $tenant->logoUrl(), 'color' => $tenant->primary_color, 'vat_number' => $tenant->setting('vat_number'), 'cr_number' => $tenant->setting('cr_number')],
        ]);
    }

    /** The paper drawn with the values on screen, saved or not. */
    public function preview(Request $request, string $type): JsonResponse
    {
        abort_unless(PaperTypes::exists($type), 404);
        $data = $request->validate(PaperTypes::rules($type) + ['face' => ['nullable', 'in:sheet,strip']]);
        $paper = Papers::preview($type, $request->user(), $data, $data['face'] ?? 'sheet');

        return response()->json(['html' => $paper->html(), 'size' => $paper->size]);
    }

    public function update(Request $request, string $type, AuditLogger $audit): RedirectResponse
    {
        abort_unless(PaperTypes::exists($type), 404);
        $data = $request->validate(PaperTypes::rules($type));
        $tenant = $this->tenants->require();
        $before = PaperTypes::settings($tenant, $type);
        PaperTypes::save($tenant, $type, $data);
        $after = PaperTypes::settings($tenant->refresh(), $type);
        $audit->log('papers.updated', null, ['paper' => $type], array_diff_assoc(array_map('strval', $before), array_map('strval', $after)), array_diff_assoc(array_map('strval', $after), array_map('strval', $before)));

        return back()->with('success', 'حُفظ قالب «'.PaperTypes::TYPES[$type]['label'].'». يظهر على كل ورقة تُطبع من الآن.');
    }

    public function reset(string $type, AuditLogger $audit): RedirectResponse
    {
        abort_unless(PaperTypes::exists($type), 404);
        PaperTypes::reset($this->tenants->require(), $type);
        $audit->log('papers.reset', null, ['paper' => $type]);

        return back()->with('success', 'أُعيد قالب «'.PaperTypes::TYPES[$type]['label'].'» إلى إعداداته الافتراضية.');
    }
}
