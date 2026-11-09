<?php

namespace App\Modules\Website\Http\Controllers;

use App\Core\Notifications\StaffNotifier;
use App\Http\Controllers\Controller;
use App\Modules\Website\Models\ContactMessage;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/** Website content: texts, images, contact details, SEO, gallery, contact-form inbox. */
class CmsController extends Controller
{
    public function messages(): Response
    {
        return Inertia::render('Website/Messages', [
            'messages' => ContactMessage::latest()->paginate(30)->through(fn ($m) => $m->only('id', 'name', 'phone', 'email', 'subject', 'message', 'status') + ['at' => $m->created_at->format('Y-m-d H:i')]),
        ]);
    }

    public function markRead(ContactMessage $message): RedirectResponse
    {
        $message->update(['status' => 'read']);
        app(StaffNotifier::class)->resolve('contact:'.$message->id);

        return back();
    }
}
