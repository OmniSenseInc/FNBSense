<?php

use Illuminate\Support\Facades\Route;

Route::get('ping', fn () => response()->json(['service' => 'notification', 'status' => 'ok']));
