<?php

namespace App\Service;

use App\Models\CarryBeeOrderCreateForm;
use App\Models\CarrybeeWebhookEvent;
use App\Models\DeliveryAssignedInfo;
use App\Models\Order;
use App\Models\OrderStatusHistory;
use App\Models\Transaction;
use Illuminate\Support\Arr;

class CarrybeeWebhookService
{
    public function process(array $payload, string $rawBody, string $signature, array $headers = []): array
    {
        $event = (string) ($payload['event'] ?? '');
        if ($event === '') {
            return [
                'ok' => false,
                'http_code' => 422,
                'message' => 'Invalid payload: event is required',
            ];
        }

        $fingerprint = $this->buildFingerprint($payload);
        $webhookEvent = CarrybeeWebhookEvent::where('fingerprint', $fingerprint)->first();

        if ($webhookEvent) {
            $webhookEvent->attempts = (int) $webhookEvent->attempts + 1;

            if ($webhookEvent->processed_at !== null) {
                $webhookEvent->processing_status = 'duplicate';
                $webhookEvent->message = 'Duplicate webhook ignored';
                $webhookEvent->save();

                return [
                    'ok' => true,
                    'http_code' => 200,
                    'message' => 'Duplicate webhook ignored',
                    'data' => ['event_id' => $webhookEvent->id],
                ];
            }
        } else {
            $webhookEvent = CarrybeeWebhookEvent::create([
                'fingerprint' => $fingerprint,
                'event' => $event,
                'store_id' => Arr::get($payload, 'store_id'),
                'consignment_id' => Arr::get($payload, 'consignment_id'),
                'merchant_order_id' => Arr::get($payload, 'merchant_order_id'),
                'event_time' => Arr::get($payload, 'timestamptz'),
                'request_headers' => $headers,
                'payload' => $payload,
                'processing_status' => 'received',
            ]);
        }

        $signatureValid = $this->isValidSignature($rawBody, $signature);
        $webhookEvent->signature_valid = $signatureValid;

        if (!$signatureValid) {
            $webhookEvent->processing_status = 'invalid_signature';
            $webhookEvent->message = 'Signature verification failed';
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return [
                'ok' => false,
                'http_code' => 401,
                'message' => 'Invalid webhook signature',
            ];
        }

        $mappedStatusId = config('carrybee.event_status_map.' . $event);
        if (!array_key_exists($event, (array) config('carrybee.event_status_map', []))) {
            $webhookEvent->processing_status = 'ignored';
            $webhookEvent->message = 'Unsupported event: ' . $event;
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return [
                'ok' => true,
                'http_code' => 200,
                'message' => 'Event ignored (unsupported)',
                'data' => ['event_id' => $webhookEvent->id],
            ];
        }

        $order = $this->resolveOrder($payload);
        if (!$order) {
            $webhookEvent->processing_status = 'ignored';
            $webhookEvent->message = 'Order not found for webhook identifiers';
            $webhookEvent->processed_at = now();
            $webhookEvent->save();

            return [
                'ok' => true,
                'http_code' => 202,
                'message' => 'Order not found, event stored',
                'data' => ['event_id' => $webhookEvent->id],
            ];
        }

        $webhookEvent->order_id = $order->id;
        $webhookEvent->mapped_status_id = $mappedStatusId;

        $statusMessage = 'No status mutation required';
        if ($mappedStatusId !== null) {
            $statusMessage = $this->applyStatusTransition($order, (int) $mappedStatusId, $payload);
        }

        $this->syncDeliveryAssignmentData($order, $payload, $mappedStatusId);

        $webhookEvent->processing_status = 'processed';
        $webhookEvent->message = $statusMessage;
        $webhookEvent->processed_at = now();
        $webhookEvent->save();

        return [
            'ok' => true,
            'http_code' => 200,
            'message' => 'Webhook processed',
            'data' => [
                'event_id' => $webhookEvent->id,
                'order_id' => $order->id,
                'status' => $statusMessage,
            ],
        ];
    }

    private function buildFingerprint(array $payload): string
    {
        $key = implode('|', [
            (string) Arr::get($payload, 'event', ''),
            (string) Arr::get($payload, 'store_id', ''),
            (string) Arr::get($payload, 'consignment_id', ''),
            (string) Arr::get($payload, 'merchant_order_id', ''),
            (string) Arr::get($payload, 'timestamptz', ''),
        ]);

        return hash('sha256', $key);
    }

    private function isValidSignature(string $rawBody, string $received): bool
    {
        $secret = (string) config('carrybee.webhook.secret', '');
        if ($secret === '') {
            return false;
        }

        $received = trim($received, " \t\n\r\0\x0B\"'");
        if ($received === '') {
            return false;
        }

        if (str_starts_with($received, 'sha256=')) {
            $received = substr($received, 7);
        }

        if (hash_equals($secret, $received)) {
            return true;
        }

        $computed = hash_hmac('sha256', $rawBody, $secret);
        return hash_equals($computed, $received);
    }

    private function resolveOrder(array $payload): ?Order
    {
        $consignmentId = Arr::get($payload, 'consignment_id');
        if (!empty($consignmentId)) {
            $assigned = DeliveryAssignedInfo::where('consignment_id', $consignmentId)->first();
            if ($assigned?->order_id) {
                return Order::find($assigned->order_id);
            }
        }

        $merchantOrderId = Arr::get($payload, 'merchant_order_id');
        if (!empty($merchantOrderId)) {
            $assigned = DeliveryAssignedInfo::where('merchant_order_id', $merchantOrderId)->first();
            if ($assigned?->order_id) {
                return Order::find($assigned->order_id);
            }

            $draft = CarryBeeOrderCreateForm::where('merchant_order_id', $merchantOrderId)->first();
            if ($draft?->order_id) {
                return Order::find($draft->order_id);
            }
        }

        return null;
    }

    private function applyStatusTransition(Order $order, int $targetStatusId, array $payload): string
    {
        $currentStatusId = (int) $order->status;
        $rankMap = (array) config('carrybee.status_rank_map', []);
        $currentRank = (int) ($rankMap[$currentStatusId] ?? $currentStatusId);
        $targetRank = (int) ($rankMap[$targetStatusId] ?? $targetStatusId);

        if ($targetRank < $currentRank) {
            return 'Skipped status regression';
        }

        if ($targetStatusId !== $currentStatusId) {
            $order->status = $targetStatusId;
        }

        if ($targetStatusId === 9) {
            $order->payment_status = 'paid';
            $this->ensureCreditTransactionForCompletedOrder($order);
        }

        $order->save();

        if ($targetStatusId !== $currentStatusId) {
            OrderStatusHistory::create([
                'order_id' => $order->id,
                'status_id' => $targetStatusId,
                'note' => $this->buildHistoryNote($payload),
                'changed_by' => null,
            ]);

            return 'Order status updated';
        }

        return 'Order status unchanged';
    }

    private function ensureCreditTransactionForCompletedOrder(Order $order): void
    {
        $hasCredit = Transaction::where('order_id', $order->id)
            ->where('trx_type', 'credit')
            ->where('type', 'order_status')
            ->exists();

        if ($hasCredit) {
            return;
        }

        Transaction::create([
            'amount' => $order->total,
            'trx_type' => 'credit',
            'status' => 'completed',
            'source' => 'cod',
            'order_id' => $order->id,
            'type' => 'order_status',
            'note' => 'Credit transaction for order #' . $order->order_number,
        ]);
    }

    private function syncDeliveryAssignmentData(Order $order, array $payload, ?int $mappedStatusId): void
    {
        $assigned = DeliveryAssignedInfo::where('order_id', $order->id)->first();
        if (!$assigned) {
            return;
        }

        if (Arr::has($payload, 'collectable_amount')) {
            $assigned->collectable_amount = (float) Arr::get($payload, 'collectable_amount', 0);
        }

        if (Arr::has($payload, 'delivery_fee')) {
            $assigned->delivery_fee = (float) Arr::get($payload, 'delivery_fee', 0);
        }

        if (Arr::has($payload, 'total_fee')) {
            $assigned->total_fee = (float) Arr::get($payload, 'total_fee', 0);
        }

        if ($mappedStatusId !== null) {
            $assigned->transfer_status_id = $mappedStatusId;
        }

        $assigned->save();
    }

    private function buildHistoryNote(array $payload): string
    {
        $event = (string) Arr::get($payload, 'event', 'unknown');
        $attempt = Arr::get($payload, 'attempt');
        $reason = Arr::get($payload, 'reason');
        $remarks = Arr::get($payload, 'remarks');

        $parts = ['CarryBee webhook: ' . $event];

        if ($attempt !== null) {
            $parts[] = 'attempt=' . $attempt;
        }

        if ($reason !== null && $reason !== '') {
            $parts[] = 'reason=' . $reason;
        }

        if ($remarks !== null && $remarks !== '') {
            $parts[] = 'remarks=' . $remarks;
        }

        return implode(' | ', $parts);
    }
}
