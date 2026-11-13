<?php

namespace App\Http\Controllers\Club;

use App\Core\Support\Images\SpacesOriginals;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * A signed address for the browser to put one original straight into the Spaces bucket, for one place (members,
 * products…): each place has its own route under its own permission, and the ticket is good for that place only.
 * Not found while originals are kept on the droplet (MEDIA_ORIGINALS=local, the default).
 */
class ImageTicketController extends Controller
{
    public function __invoke(Request $request, string $folder): JsonResponse
    {
        abort_unless(SpacesOriginals::enabled(), 404);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:100'],
            'size' => ['required', 'integer', 'min:1', 'max:'.config('gym.max_image_kb') * 1024],
            'sha256' => ['required', 'string', 'regex:/^[0-9a-f]{64}$/'],
            'md5' => ['required', 'string', 'regex:/^[0-9a-f]{32}$/'],
        ]);

        return response()->json(SpacesOriginals::ticket($folder, $data['name'], (string) ($data['type'] ?? ''), (int) $data['size'], $data['sha256'], $data['md5']));
    }
}
