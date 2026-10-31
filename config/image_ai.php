<?php
return [
    'url' => env('IMAGE_AI_URL', 'http://127.0.0.1:8779'),
    'timeout' => (int) env('IMAGE_AI_TIMEOUT', 45),
    'connect_timeout' => (int) env('IMAGE_AI_CONNECT_TIMEOUT', 3),
    'search_mode' => env('IMAGE_AI_SEARCH_MODE', 'single'),
    'sumopod' => [
        'enabled' => filter_var(env('SUMOPOD_ENABLED', false), FILTER_VALIDATE_BOOL),
        'api_key' => env('SUMOPOD_API_KEY'),
        'base_url' => env('SUMOPOD_BASE_URL', 'https://ai.sumopod.com/v1'),
        'model' => env('SUMOPOD_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('SUMOPOD_TIMEOUT', 45),
        'candidates' => max(5, min(20, (int) env('SUMOPOD_CANDIDATES', 8))),
    ],
];
