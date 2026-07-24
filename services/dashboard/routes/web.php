<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return redirect('/admin');
});

Route::get('/health', fn () => response()->json([
    'service' => 'dashboard',
    'status' => 'ok',
]));
