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

Schedule::command('feed:build --due --publish --queue')
    ->name('feed:build-due')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping();

Schedule::command('feed:publish --due --queue')
    ->name('feed:publish-due')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping();
