<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Core\Tenancy\TenantRule;
use App\Http\Controllers\Controller;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\Member;
use App\Modules\Gym\Services\BookingService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class BookingController extends Controller
{
    public function __construct(private readonly BookingService $bookings) {}

    public function store(Request $request, ClassSession $session): RedirectResponse
    {
        $data = $request->validate(['member_id' => ['required', TenantRule::exists('gym_members')]]);
        $this->bookings->book(Member::findOrFail($data['member_id']), $session, 'reception');

        return back()->with('success', 'تم الحجز.');
    }

    public function cancel(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:255']]);
        $this->bookings->cancel($booking, true, $data['reason'] ?? 'إلغاء من الاستقبال');

        return back()->with('success', 'تم إلغاء الحجز واستعادة الحصة.');
    }

    public function attendance(Request $request, Booking $booking): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', 'in:attended,no_show']]);

        // A trainer may only mark her own classes.
        $user = $request->user();
        if ($user->isTrainer()) {
            $booking->loadMissing('session');
            abort_unless($user->trainer && $booking->session->trainer_id === $user->trainer->id, 403);
        }

        $this->bookings->markAttendance($booking, $data['status']);

        return back()->with('success', $data['status'] === 'attended' ? 'تم تسجيل الحضور.' : 'تم تسجيل الغياب.');
    }
}
