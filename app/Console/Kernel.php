<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        \App\Console\Commands\IndexImages::class,
        \App\Console\Commands\SyncDriveImages::class,
        \App\Console\Commands\BuildGalleryThumbnails::class,
    ];

    protected function schedule(Schedule $schedule): void
    {
        //
    }

    protected function commands(): void
    {
        require base_path('routes/console.php');
    }
}