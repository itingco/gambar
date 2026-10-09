<?php
namespace App\Console;
use Illuminate\Console\Scheduling\Schedule;
class Kernel extends \Illuminate\Foundation\Console\Kernel {
protected $commands=[\App\Console\Commands\IndexImages::class,\App\Console\Commands\SyncDriveImages::class];
protected function schedule(Schedule $schedule):void{}
protected function commands():void{require base_path('routes/console.php');}
}
