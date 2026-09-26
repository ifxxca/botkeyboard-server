<?php

return [
    'client_checks_per_minute' => (int) env('ATTENTION_CLIENT_CHECKS_PER_MINUTE', 600),
    'client_online_seconds' => (int) env('ATTENTION_CLIENT_ONLINE_SECONDS', 90),
    'display_timezone' => env('ATTENTION_DISPLAY_TIMEZONE', 'Asia/Jakarta'),
];
