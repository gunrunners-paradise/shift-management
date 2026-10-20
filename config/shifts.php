<?php

return [
    // Scheduled periods, not check-in records or paid-hours calculations.
    // Validation, the Blade grid and deactivation use this single definition.
    'periods' => [
        '09_13' => [
            'label' => 'משמרת 09:00–13:00',
            'start' => '09:00',
            'end' => '13:00',
            'duration_minutes' => 240,
        ],
        '10_14' => [
            'label' => 'משמרת 10:00–14:00',
            'start' => '10:00',
            'end' => '14:00',
            'duration_minutes' => 240,
        ],
        '11_15' => [
            'label' => 'משמרת 11:00–15:00',
            'start' => '11:00',
            'end' => '15:00',
            'duration_minutes' => 240,
        ],
        '12_16' => [
            'label' => 'משמרת 12:00–16:00',
            'start' => '12:00',
            'end' => '16:00',
            'duration_minutes' => 240,
        ],
        'full' => [
            'label' => 'משמרת מלאה',
            'start' => '09:00',
            'end' => '16:30',
            'duration_minutes' => 450,
        ],
    ],

    'booking_horizon_days' => 28,
];
