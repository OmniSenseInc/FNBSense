<?php

use Illuminate\Support\Facades\Artisan;

Artisan::command('about:reporting', function () {
    $this->info('FNBSense Reporting F6a');
})->purpose('Tampilkan identitas service Reporting.');
