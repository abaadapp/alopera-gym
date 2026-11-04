<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A request made in the background (the bell's feed, a live preview, a search) leaves the session's one-time messages
 * for the page they were meant for. Without this, the bell asking at the moment a dialog was refused ate its errors:
 * the page came back clean and the dialog closed as if saved, with nothing saved.
 */
final class KeepFlashForThePage
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->hasSession() && ! $request->header('X-Inertia') && ($request->expectsJson() || $request->ajax())) {
            $request->session()->reflash();
        }

        return $response;
    }
}
