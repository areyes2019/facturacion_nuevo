<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
 * Caducidad de cotizaciones (ver 008-cotizaciones.md). En este entorno (Windows/Laragon) no hay
 * cron: el scheduler solo corre si existe una tarea programada de Windows que ejecute
 * `php artisan schedule:run` cada minuto — pasos en backend/README.md.
 */
Schedule::command('cotizaciones:purgar-vencidas')->dailyAt('03:00');
