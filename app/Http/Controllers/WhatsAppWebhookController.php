<?php

namespace App\Http\Controllers;

use App\Service\WhatsAppCloudService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

class WhatsAppWebhookController extends Controller
{
    public function __construct(private readonly WhatsAppCloudService $whatsAppService)
    {
    }

    /**
     * Meta Webhook Verification Handshake.
     * GET /api/webhooks/whatsapp?hub.mode=subscribe&hub.challenge=...&hub.verify_token=...
     */
    public function verify(Request $request): Response
    {
        $mode = $request->query('hub_mode', $request->query('hub.mode'));
        $token = $request->query('hub_verify_token', $request->query('hub.verify_token'));
        $challenge = $request->query('hub_challenge', $request->query('hub.challenge'));

        $verifiedChallenge = $this->whatsAppService->verifyWebhook($mode, $token, $challenge);

        if ($verifiedChallenge !== null) {
            return response($verifiedChallenge, 200, ['Content-Type' => 'text/plain']);
        }

        Log::warning('WhatsApp webhook verification failed: invalid token or mode.', [
            'mode' => $mode,
            'ip' => $request->ip(),
        ]);

        return response('Forbidden', 403);
    }

    /**
     * Process Meta Webhook Events (Status Receipts & Inbound Messages).
     * POST /api/webhooks/whatsapp
     */
    public function handle(Request $request)
    {
        try {
            $payload = $request->all();
            $result = $this->whatsAppService->processWebhookPayload($payload);

            return response()->json([
                'status' => 'success',
                'data' => $result,
            ], 200);
        } catch (\Throwable $e) {
            Log::error('Error processing WhatsApp webhook payload: ' . $e->getMessage(), [
                'exception' => $e->getTraceAsString(),
            ]);

            // Meta requires an HTTP 200 acknowledgment to avoid retry storms
            return response()->json([
                'status' => 'error',
                'message' => 'Exception occurred during webhook processing',
            ], 200);
        }
    }
}
