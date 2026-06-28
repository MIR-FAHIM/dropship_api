<?php

return [
    'webhook' => [
        'signature_header' => env('CARRYBEE_WEBHOOK_SIGNATURE_HEADER', 'X-Carrybee-Webhook-Signature'),
        'secret' => env('CARRYBEE_WEBHOOK_SECRET', ''),
    ],

    // Maps external CarryBee event names to local order_statuses.id.
    // Null means no order status mutation; event is logged only.
    'event_status_map' => [
        'order.created' => 4,
        'order.updated' => null,
        'order.create-failed' => null,
        'order.pickup-requested' => 3,
        'order.assigned-for-pickup' => 3,
        'order.picked' => 4,
        'order.pickup-failed' => 3,
        'order.pickup-cancelled' => 7,
        'order.at-the-sorting-hub' => 4,
        'order.on-the-way-to-central-warehouse' => 4,
        'order.at-central-warehouse' => 4,
        'order.in-transit' => 4,
        'order.received-at-last-mile-hub' => 4,
        'order.assigned-for-delivery' => 5,
        'order.delivery-on-hold' => 5,
        'order.delivered' => 6,
        'order.partial-delivery' => 6,
        'order.delivery-failed' => 5,
        'order.returned' => 8,
        'order.paid-return' => 8,
        'order.exchange' => 6,
        'order.paid' => 9,
        'order.returned-at-sorting' => 8,
        'order.returned-in-transit' => 8,
        'order.returned-to-merchant' => 8,
    ],

    // Used to block status regression from webhook updates.
    'status_rank_map' => [
        1 => 1,
        2 => 2,
        3 => 3,
        4 => 4,
        5 => 5,
        6 => 6,
        7 => 7,
        8 => 8,
        9 => 9,
        10 => 10,
    ],
];
