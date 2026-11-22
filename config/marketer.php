<?php

return [
    // Partner Portal: % of the platform profit (product markup + delivery margin) a marketer
    // earns on each delivered + CarryBee-paid order of their clients.
    // Rate used: users.marketer_commission_rate (admin's custom rate) → level rate below → this default.
    'commission_rate' => (float) env('MARKETER_COMMISSION_RATE', 20),

    // F8 levels (agreed 2026-11-22): Upper Level (Pro) 20%, Mid Level 15%.
    'level_rates' => [
        'pro' => (float) env('MARKETER_PRO_RATE', 20),
        'mid' => (float) env('MARKETER_MID_RATE', 15),
    ],
];
