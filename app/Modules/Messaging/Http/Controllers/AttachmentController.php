<?php

namespace App\Modules\Messaging\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Messaging\Models\Attachment;
use App\Modules\Messaging\Services\ConversationAccess;
use App\Modules\Messaging\Services\MediaStore;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * A chat file, served from the private disk to whoever may see its conversation (anyone else: 404, the same as a file
 * that does not exist). Pictures, sound and video open in the page; every other file downloads. Never cached.
 */
class AttachmentController extends Controller
{
    private const INLINE = ['image/jpeg', 'image/png', 'image/webp', 'audio/aac', 'audio/mp4', 'audio/mpeg', 'audio/ogg', 'audio/amr', 'video/mp4', 'video/3gpp'];

    public function __invoke(Request $request, Attachment $attachment, ConversationAccess $access, MediaStore $store): BinaryFileResponse
    {
        $conversation = $attachment->message?->conversation;
        abort_unless($conversation && $access->canView($request->user(), $conversation), 404);
        $path = $attachment->status === Attachment::STORED ? $store->absolutePath($attachment) : null;
        abort_if($path === null, 404);

        $mime = (string) ($attachment->mime ?: 'application/octet-stream');
        $name = $attachment->filename ?: ($attachment->kind.'-'.$attachment->id.'.'.pathinfo($path, PATHINFO_EXTENSION));
        $inline = in_array($mime, self::INLINE, true) && ! $request->boolean('download');

        return response()->file($path, [
            'Content-Type' => $mime,
            'Content-Disposition' => ($inline ? 'inline' : 'attachment').'; filename*=UTF-8\'\''.rawurlencode($name),
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
