<?php

return [
    // Partner Portal: % of the platform profit (product markup + delivery margin) a marketer
    // earns on each delivered + CarryBee-paid order of their clients. Per marketer override:
    // users.marketer_commission_rate.
    'commission_rate' => (float) env('MARKETER_COMMISSION_RATE', 20),
];
