<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('source:sync --due --queue')
    ->everyTenMinutes()
    ->withoutOverlapping();

Schedule::command('feed:build --due --publish --queue')
    ->everyFifteenMinutes()
    ->withoutOverlapping();
