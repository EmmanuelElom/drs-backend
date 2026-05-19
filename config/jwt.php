<?php

return [
    'secret' => env('JWT_SECRET', env('APP_KEY', 'change-me')),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'http://localhost:8000')),
    'audience' => env('JWT_AUDIENCE', env('FRONTEND_URL', 'http://localhost:5173')),
    'access_ttl' => (int) env('JWT_ACCESS_TTL', 60),
    'refresh_ttl' => (int) env('JWT_REFRESH_TTL', 20160),
    'clock_skew' => (int) env('JWT_CLOCK_SKEW', 30),
    'blacklist_store' => env('JWT_BLACKLIST_STORE', env('CACHE_STORE', 'database')),
];

