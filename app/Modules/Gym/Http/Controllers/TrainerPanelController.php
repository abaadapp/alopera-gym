<?php

namespace App\Modules\Gym\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Gym\Http\GymPresenter;
use App\Modules\Gym\Models\Booking;
use App\Modules\Gym\Models\ClassSession;
use App\Modules\Gym\Models\PtSession;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/** The trainer's own screen: her schedule, rosters and attendance (spec 1.10). */
class TrainerPanelController extends Controller
{
    public function index(Request $request): Response
    {
        $trainer = $request->user()->trainer;
        $from = CarbonImmutable::today();

        return Inertia::render('Gym/TrainerPanel/Index', [
            'trainer' => $trainer?->only('id', 'name'),
            'sessions' => $trainer ? ClassSession::with('program:id,name,color')->where('trainer_id', $trainer->id)
                ->between($from, $from->addDays(7))->where('status', '!=', ClassSession::CANCELLED)
                ->orderBy('starts_at')->get()->map(fn ($s) => GymPresenter::session($s)) : [],
            'recent' => $trainer ? ClassSession::with('program:id,name,color')->where('trainer_id', $trainer->id)
                ->between($from->subDays(7), $from)->orderByDesc('starts_at')->limit(10)->get()->map(fn ($s) => GymPresenter::session($s)) : [],
            'pt' => $trainer ? PtSession::with('member:id,full_name,phone')->where('trainer_id', $trainer->id)
                ->where('starts_at', '>=', $from->subDay())->orderBy('starts_at')->limit(30)->get()
                ->map(fn ($s) => ['id' => $s->id, 'member' => $s->member?->full_name, 'phone' => $s->member?->phone, 'starts_at' => $s->starts_at->format('Y-m-d H:i'), 'ends_at' => $s->ends_at->format('H:i'), 'status' => $s->status, 'notes' => $s->notes]) : [],
        ]);
    }

    public function session(Request $request, ClassSession $session): Response
    {
        $user = $request->user();
        // Trainers see only their own sessions; managers with classes.manage see any.
        if (! $user->can('classes.manage')) {
            abort_unless($user->trainer && $session->trainer_id === $user->trainer->id, 403);
        }
        $session->load(['program:id,name,color', 'trainer:id,name']);

        return Inertia::render('Gym/TrainerPanel/Session', [
            'session' => GymPresenter::session($session),
            'bookings' => Booking::with('member:id,full_name,member_no,phone')->where('class_session_id', $session->id)
                ->where('status', '!=', Booking::CANCELLED)->orderBy('id')->get()
                ->map(fn (Booking $b) => ['id' => $b->id, 'status' => $b->status, 'member' => $b->member?->only('id', 'full_name', 'member_no', 'phone')]),
        ]);
    }
}
