<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    protected $commands = [
        Commands\SyncData::class,
        Commands\SyncTest::class,
    ];

    protected function schedule(Schedule $schedule)
    {
        // sync tiap 5 menit,
        // skip run baru kalau run sebelumnya masih jalan lewat 60 menit
        $schedule->command('sync:data')->everyFiveMinutes()->withoutOverlapping(60)->runInBackground();

        // Test sync tiap jam
        $schedule->command('sync:test')->hourly()->appendOutputTo(storage_path('logs/sync-test.log'));
    }

    protected function commands()
    {
        $this->load(__DIR__.'/Commands');
        require base_path('routes/console.php');
    }
}
