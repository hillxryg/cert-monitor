<?php

return [
    // Expected client heartbeat cadence (seconds).
    'heartbeat_interval' => 15,

    // Slack for network jitter / timer drift (seconds).
    'timing_tolerance' => 2.0,

    // A gap longer than interval * multiplier between heartbeats is logged.
    'gap_threshold_multiplier' => 3,

    // The same event type is logged/deducted at most once per lesson per this window.
    'log_cooldown_seconds' => 60,

    // Hard cap on points deducted by a single heartbeat.
    'max_deduction_per_heartbeat' => 10,

    // Reading lessons: scroll jump (percentage points) within one heartbeat that counts as skimming.
    'scroll_jump_percent' => 50,

    // Reading lessons: active seconds required per 1% of scroll depth to count as read.
    'reading_seconds_per_percent' => 0.5,

    // Points deducted from enrollments.integrity_score per event type.
    'points' => [
        'seek_skip' => 5,
        'speed_violation' => 5,
        'muted_playback' => 3,
        'tab_hidden' => 2,
        'heartbeat_gap' => 0,
        'scroll_too_fast' => 3,
        'idle_timeout' => 2,
        'multi_session' => 10,
    ],
];
