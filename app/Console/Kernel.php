<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Console\Kernel as ConsoleKernel;

class Kernel extends ConsoleKernel
{
    /**
     * The Artisan commands provided by your application.
     *
     * @var array
     */
    protected $commands = [
        \App\Console\Commands\UpdateExpiredDocuments::class,
    ];

    /**
     * Define the application's command schedule.
     */
    protected function schedule(Schedule $schedule): void
    {
        // Mark vendor registration documents as expired if past expiry date
        // Runs daily at midnight (00:00)
        $schedule->command('documents:mark-expired')
            ->dailyAt('00:00')
            ->withoutOverlapping(10)  // Prevent overlapping, timeout 10 minutes
            ->onSuccess(function () {
                \Log::info('UpdateExpiredDocuments command completed successfully');
            })
            ->onFailure(function () {
                \Log::error('UpdateExpiredDocuments command failed');
            });

        // Optional: Also run at noon to catch any edge cases
        // $schedule->command('documents:mark-expired')
        //     ->dailyAt('12:00');
    }

    /**
     * Register the commands for the application.
     */
    protected function commands(): void
    {
        $this->load(__DIR__.'/Commands');

        require base_path('routes/console.php');
    }
}
