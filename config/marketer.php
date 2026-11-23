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

    // F7 Tour Milestones (agreed 2026-11-22: motivation only — progress is shown, nothing is claimed or paid).
    // Progress = delivered orders of the marketer's clients, all time. Targets are cumulative.
    'tours' => [
        ['id' => 'TOUR-1', 'target' => 1500, 'en' => "Sajek / Cox's Bazar (Single)", 'bn' => 'সাজেক / কক্সবাজার (সিঙ্গেল)',
            'detail_en' => '3 Days 2 Nights — All-Inclusive Single Package', 'detail_bn' => '৩ দিন ২ রাত — অল-ইনক্লুসিভ সিঙ্গেল প্যাকেজ'],
        ['id' => 'TOUR-2', 'target' => 3000, 'en' => "Sajek / Cox's Bazar (Couple)", 'bn' => 'সাজেক / কক্সবাজার (কাপল)',
            'detail_en' => '3 Days 2 Nights — Premium Couple Package', 'detail_bn' => '৩ দিন ২ রাত — প্রিমিয়াম কাপল প্যাকেজ'],
        ['id' => 'TOUR-3', 'target' => 7000, 'en' => 'Darjeeling + Sikkim — 5 Days', 'bn' => 'দার্জিলিং + সিকিম — ৫ দিন',
            'detail_en' => '5 Days 4 Nights — Single Tour Package', 'detail_bn' => '৫ দিন ৪ রাত — সিঙ্গেল ট্যুর প্যাকেজ'],
    ],
    // Bigger dream goals shown after the core tours (no target yet).
    'global_tours' => [
        ['id' => 'kashmir', 'tag' => 'Next #1', 'en' => 'Kashmir', 'bn' => 'কাশ্মীর'],
        ['id' => 'thailand', 'tag' => 'Next #2', 'en' => 'Thailand', 'bn' => 'থাইল্যান্ড'],
        ['id' => 'indonesia', 'tag' => 'Next #3', 'en' => 'Indonesia', 'bn' => 'ইন্দোনেশিয়া'],
        ['id' => 'turkey', 'tag' => 'Next #4', 'en' => 'Turkey', 'bn' => 'তুরস্ক'],
        ['id' => 'umrah', 'tag' => 'VIP Goal', 'en' => 'Saudi Umrah', 'bn' => 'সৌদি উমরাহ'],
    ],
];
