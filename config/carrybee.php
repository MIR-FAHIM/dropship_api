<?php

/*
 * CarryBee integration settings. Webhook contract:
 * https://hackmd.io/_wLl0AtKRHGKBIIsF_xssg?view (CarryBee Developers' API v2.0)
 *
 * Internal order_statuses ids:
 *  1 Pending, 2 Assigned To Vendor, 3 Vendor Is Ready, 4 Shipped, 5 Out for Delivery,
 *  6 Delivered, 7 Cancelled, 8 Returned, 9 Completed, 10 Carrybee Paid,
 *  11 Lost / Damaged, 12 Return in Transit, 13 Partially Delivered
 */
return [
    'webhook' => [
        'signature_header' => env('CARRYBEE_WEBHOOK_SIGNATURE_HEADER', 'X-Carrybee-Webhook-Signature'),
        'secret' => env('CARRYBEE_WEBHOOK_SECRET', ''),
    ],

    // CarryBee event -> internal order_statuses.id. Null = no status change; the
    // event still updates courier tracking (stage, attempt, rider, issue flags).
    'event_status_map' => [
        'order.created' => null,                       // consignment booked, parcel still with vendor
        'order.create-failed' => null,
        'order.updated' => null,                       // fee / amount changes only
        'order.pickup-requested' => null,
        'order.assigned-for-pickup' => null,
        'order.pickup-failed' => null,                 // pickup issue flag
        'order.pickup-cancelled' => 7,                 // only applied before pickup
        'order.picked' => 4,
        'order.at-the-sorting-hub' => 4,
        'order.on-the-way-to-central-warehouse' => 4,
        'order.at-central-warehouse' => 4,
        'order.in-transit' => 4,
        'order.received-at-last-mile-hub' => 4,
        'order.assigned-for-delivery' => 5,
        'order.delivery-on-hold' => null,              // delivery issue flag
        'order.delivery-failed' => null,               // delivery issue flag
        'order.delivered' => 6,
        'order.partial-delivery' => 13,
        'order.exchange' => null,                      // separate E-consignment, tracking only
        'order.paid' => 10,
        'order.returned' => 12,
        'order.paid-return' => 12,
        'order.returned-at-sorting' => 12,
        'order.returned-in-transit' => 12,
        'order.returned-to-merchant' => 8,             // parcel back with vendor: money reversed here
    ],

    // Readable labels for the courier timeline.
    'event_labels' => [
        'order.created' => 'Consignment created',
        'order.create-failed' => 'Consignment failed',
        'order.updated' => 'Consignment updated',
        'order.pickup-requested' => 'Pickup requested',
        'order.assigned-for-pickup' => 'Assigned for pickup',
        'order.pickup-failed' => 'Pickup failed',
        'order.pickup-cancelled' => 'Pickup cancelled',
        'order.picked' => 'Picked up',
        'order.at-the-sorting-hub' => 'At sorting hub',
        'order.on-the-way-to-central-warehouse' => 'On the way to central warehouse',
        'order.at-central-warehouse' => 'At central warehouse',
        'order.in-transit' => 'In transit',
        'order.received-at-last-mile-hub' => 'At delivery hub',
        'order.assigned-for-delivery' => 'Out for delivery',
        'order.delivery-on-hold' => 'Delivery on hold',
        'order.delivery-failed' => 'Delivery failed',
        'order.delivered' => 'Delivered',
        'order.partial-delivery' => 'Partially delivered',
        'order.exchange' => 'Exchange',
        'order.paid' => 'CarryBee paid',
        'order.returned' => 'Returning',
        'order.paid-return' => 'Returning (delivery charge collected)',
        'order.returned-at-sorting' => 'Return at sorting hub',
        'order.returned-in-transit' => 'Return in transit',
        'order.returned-to-merchant' => 'Returned to vendor',
    ],

    // Order of progress, used to block status regressions. Equal rank = allowed.
    'status_rank_map' => [
        1 => 1,
        2 => 2,
        3 => 3,
        4 => 4,
        5 => 5,
        6 => 6,
        13 => 6,
        10 => 7,
        9 => 8,
        12 => 9,
        8 => 10,
        7 => 10,
        11 => 10,
    ],

    // No automatic move out of these; an admin override (with reason) is required.
    'final_statuses' => [7, 8, 9, 11],

    // Statuses that, once a consignment exists, only CarryBee sets.
    'courier_statuses' => [4, 5, 6, 8, 10, 12, 13],

    'catch_up' => [
        // A consignment is re-checked when CarryBee has been quiet this long.
        'quiet_hours' => (int) env('CARRYBEE_CATCH_UP_QUIET_HOURS', 6),
        'batch_size' => (int) env('CARRYBEE_CATCH_UP_BATCH', 50),
        // Order statuses worth asking CarryBee about (still moving or waiting for payment).
        'active_statuses' => [3, 4, 5, 6, 12, 13],
    ],
];
