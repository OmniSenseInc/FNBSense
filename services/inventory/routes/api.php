<?php

use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'inventory', 'status' => 'ok']));
