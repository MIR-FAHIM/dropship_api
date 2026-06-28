<?php

namespace App\Http\Controllers;

use App\Service\CarrybeeWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CarrybeeWebhookController extends Controller
{
    public function __construct(private readonly CarrybeeWebhookService $webhookService)
    {
    }

    public function handle(Request $request): JsonResponse
    {
        $payload = $request->json()->all();
        if (!is_array($payload) || empty($payload)) {
            $payload = $request->all();
        }

        if (!is_array($payload)) {
            $payload = [];
        }

        $rawBody = $request->getContent();
        $signatureHeader = (string) config('carrybee.webhook.signature_header', 'X-Carrybee-Webhook-Signature');
        $signature = (string) $request->header($signatureHeader, '');

        $result = $this->webhookService->process(
            payload: $payload,
            rawBody: $rawBody,
            signature: $signature,
            headers: $request->headers->all(),
        );

        return response()->json([
            'status' => $result['ok'] ? 'success' : 'failed',
            'message' => $result['message'],
            'data' => $result['data'] ?? null,
        ], $result['http_code']);
    }
}
