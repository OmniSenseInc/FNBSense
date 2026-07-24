<?php

return [
    'jwt_public_key' => env('JWT_PUBLIC_KEY', ''),
    'jwt_leeway_seconds' => env('JWT_LEEWAY_SECONDS', 30),
];
