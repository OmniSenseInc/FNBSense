<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Langkah 9: tandai order PENDING kedaluwarsa jadi EXPIRED tiap menit.
Schedule::command('orders:expire')->everyMinute();


