<?php

use App\Services\Ops\HeartbeatService;
use Illuminate\Support\Facades\Schedule;

Schedule::call(static function (): void {
    app(HeartbeatService::class)->recordSchedulerHeartbeat();
})
    ->name('ops:scheduler-heartbeat')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('source:sync --due --queue')
    ->name('source:sync-due')
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('feed:build --due --queue')
    ->name('feed:build-due')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('feed:publish --due --queue')
    ->name('feed:publish-due')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:alerts:review')
    ->name('ops:alerts-review')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:alerts:dispatch-pending')
    ->name('ops:alerts-dispatch-pending')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:alerts:escalate-due')
    ->name('ops:alerts-escalate-due')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:backup-db')
    ->name('ops:backup-db')
    ->dailyAt((string) config('feed_mediator.schedule.backup_db_at'))
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:backup-files')
    ->name('ops:backup-files')
    ->dailyAt((string) config('feed_mediator.schedule.backup_files_at'))
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:prune')
    ->name('ops:prune')
    ->dailyAt((string) config('feed_mediator.schedule.prune_at'))
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('ops:deliveries:prune')
    ->name('ops:deliveries-prune')
    ->dailyAt('04:00')
    ->onOneServer()
    ->withoutOverlapping();
