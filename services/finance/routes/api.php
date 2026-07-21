<?php

use Illuminate\Support\Facades\Route;

// Health check publik.
Route::get('ping', fn () => response()->json(['service' => 'finance', 'status' => 'ok']));

// F5c/F5d menambah rute laporan/shift/expense (owner + role keuangan) di sini.
