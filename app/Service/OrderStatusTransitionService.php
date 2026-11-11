<?php

namespace App\Service;

use App\Models\Order;
use App\Models\OrderStatusHistory;

/**
 * One set of rules for every order status change: manual (admin / vendor),
 * CarryBee webhook, catch-up job and system.
 *
 *  - No regressions (config carrybee.status_rank_map).
 *  - Final statuses (cancelled, returned, completed, lost) don't move.
 *  - On consignment orders, courier statuses come only from CarryBee.
 *  - Completed is set by the system once CarryBee has paid and everything is settled.
 *
 * An admin can override a blocked manual change by giving a reason; the reason
 * is saved in the status history. Vendors cannot override.
 */
class OrderStatusTransitionService
{
    public const CODE_OK = 'ok';
    public const CODE_UNCHANGED = 'unchanged';
    public const CODE_OVERRIDE_REQUIRED = 'override_required';
    public const CODE_NOT_ALLOWED = 'not_allowed';

    public function rank(int $statusId): int
    {
        $map = (array) config('carrybee.status_rank_map', []);

        return (int) ($map[$statusId] ?? $statusId);
    }

    public function isFinal(int $statusId): bool
    {
        return in_array($statusId, (array) config('carrybee.final_statuses', [7, 8, 9, 11]), true);
    }

    public function isCourierStatus(int $statusId): bool
    {
        return in_array($statusId, (array) config('carrybee.courier_statuses', [4, 5, 6, 8, 10, 12, 13]), true);
    }

    /**
     * Rules for automatic changes (webhook, catch-up). Returns a reason string when blocked.
     */
    public function automaticBlockReason(Order $order, int $target): ?string
    {
        $current = (int) $order->status;

        if ($target === $current) {
            return null;
        }
        if ($this->isFinal($current)) {
            return 'Order is final (status ' . $current . '); event logged only';
        }
        if ($this->rank($target) < $this->rank($current)) {
            return 'Skipped status regression';
        }

        return null;
    }

    /**
     * Rules for a person changing the status by hand.
     *
     * @return array{code: string, message: string}
     */
    public function checkManual(Order $order, int $target, bool $isAdmin, ?string $overrideReason): array
    {
        $current = (int) $order->status;
        $hasOverride = $isAdmin && trim((string) $overrideReason) !== '';

        if ($target === $current) {
            return ['code' => self::CODE_UNCHANGED, 'message' => 'Order is already in this status.'];
        }

        $problems = [];
        if ($this->isFinal($current)) {
            $problems[] = 'the order is already final';
        }
        if ($this->rank($target) < $this->rank($current)) {
            $problems[] = 'it moves the order backwards';
        }
        if ($order->hasConsignment() && $this->isCourierStatus($target)) {
            $problems[] = 'CarryBee sets this status for orders with a consignment';
        }
        if ($target === 9 && $order->hasConsignment()) {
            $problems[] = 'Completed is set automatically once CarryBee has paid and settlements are done';
        }
        if ($target === 11 && !$isAdmin) {
            $problems[] = 'only an admin can mark an order lost or damaged';
        }

        if ($problems === []) {
            return ['code' => self::CODE_OK, 'message' => 'Allowed'];
        }

        $message = 'This change needs an admin override: ' . implode('; ', $problems) . '.';
        if ($hasOverride) {
            return ['code' => self::CODE_OK, 'message' => 'Allowed by admin override'];
        }

        return [
            'code' => $isAdmin ? self::CODE_OVERRIDE_REQUIRED : self::CODE_NOT_ALLOWED,
            'message' => $isAdmin ? $message . ' Give a reason to override.' : 'Not allowed: ' . implode('; ', $problems) . '.',
        ];
    }

    /**
     * Set the status and write one history row. Caller handles locking and side effects.
     */
    public function apply(Order $order, int $target, string $source, ?int $changedBy = null, ?string $note = null): void
    {
        $order->status = $target;
        $order->save();

        OrderStatusHistory::create([
            'order_id' => $order->id,
            'status_id' => $target,
            'note' => $note,
            'changed_by' => $changedBy,
            'source' => $source,
        ]);
    }
}
