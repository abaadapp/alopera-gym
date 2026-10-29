<?php

namespace App\Http\Controllers\Club;

use App\Http\Controllers\Controller;
use App\Models\StaffNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The signed-in staff member's notifications: all of them on one page, open one (it becomes read and leads to its
 * screen), mark one or all as read. Only their own rows, in their own club — anything else is a 404.
 */
class NotificationController extends Controller
{
    public const KIND_LABELS = [
        'document' => 'المستندات', 'order' => 'طلبات الموقع', 'payment' => 'المدفوعات', 'membership' => 'العضويات',
        'integration' => 'التطبيقات التكاملية', 'contact' => 'رسائل التواصل', 'inbox' => 'واتساب',
    ];

    public function index(Request $request): Response
    {
        $view = $request->query('view') === 'unread' ? 'unread' : 'all';
        $kind = in_array($request->query('kind'), StaffNotification::KINDS, true) ? $request->query('kind') : null;
        $mine = fn () => StaffNotification::for($request->user());

        return Inertia::render('Club/Notifications', [
            // Not «notifications»: that name is the bell's (shared props), which this page must not shadow.
            'list' => $mine()->when($view === 'unread', fn ($q) => $q->unread())->when($kind, fn ($q, $k) => $q->where('kind', $k))
                ->latest('id')->paginate(30)->withQueryString()
                ->through(fn (StaffNotification $n) => self::row($n)),
            'unread' => $mine()->unread()->count(),
            'kinds' => collect(self::KIND_LABELS)->only($mine()->distinct()->pluck('kind'))->all(),
            'filters' => ['view' => $view, 'kind' => $kind],
        ]);
    }

    public function open(Request $request, int $notification): RedirectResponse
    {
        $row = StaffNotification::for($request->user())->findOrFail($notification);
        $row->read_at ??= now();
        $row->save();

        return $row->url ? redirect()->to($row->url) : redirect()->route('club.notifications.index');
    }

    public function read(Request $request, int $notification): RedirectResponse
    {
        StaffNotification::for($request->user())->whereKey($notification)->unread()->firstOrFail()->forceFill(['read_at' => now()])->save();

        return back();
    }

    public function readAll(Request $request): RedirectResponse
    {
        $count = StaffNotification::for($request->user())->unread()->update(['read_at' => now(), 'updated_at' => now()]);

        return back()->with('success', $count ? 'تم تعليم '.$count.' إشعار كمقروء.' : 'لا توجد إشعارات غير مقروءة.');
    }

    /** @return array<string, mixed> */
    public static function row(StaffNotification $n): array
    {
        return [
            'id' => $n->id,
            'kind' => $n->kind,
            'kind_label' => self::KIND_LABELS[$n->kind] ?? $n->kind,
            'text' => $n->text,
            'read' => $n->read_at !== null,
            'time' => $n->created_at->locale('ar')->diffForHumans(),
            'at' => $n->created_at->format('Y-m-d H:i'),
        ];
    }
}
