<?php

namespace App\Modules\Payments\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Modules\Payments\Services\OnlinePaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Paymob transaction-processed callback. The HMAC arrives as a query
 * parameter; the body is JSON {type, obj}.
 */
class PaymobWebhookController extends Controller
{
    public function __invoke(Request $request, OnlinePaymentService $payments): JsonResponse
    {
        $outcome = $payments->handleWebhook((array) $request->json()->all(), $request->query('hmac'), $request->ip());

        return match ($outcome) {
            'rejected_signature' => response()->json(['ok' => false], 401),
            'unknown_intent' => response()->json(['ok' => false], 404),
            default => response()->json(['ok' => true, 'outcome' => $outcome]),
        };
    }
}
