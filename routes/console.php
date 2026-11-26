<?php

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

if (($_SERVER['argv'][1] ?? null) === 'serve') {
    $scanDirectories = getenv('PHP_INI_SCAN_DIR');
    $scanDirectories = ($scanDirectories === false ? '' : $scanDirectories).PATH_SEPARATOR.base_path('config/php');
    putenv('PHP_INI_SCAN_DIR='.$scanDirectories);
    $_ENV['PHP_INI_SCAN_DIR'] = $scanDirectories;
    ServeCommand::$passthroughVariables[] = 'PHP_INI_SCAN_DIR';
}

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
