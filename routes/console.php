<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// The single scheduler of invoice recurrences, WhatsApp messages and reminders (US-12.1).
Schedule::command('recurrences:dispatch')->everyMinute()->withoutOverlapping();

// Frees the storage of WhatsApp chat files nobody opened for a while.
Schedule::command('whatsapp:prune-media')->dailyAt('04:00')->withoutOverlapping();

// Sends the scheduled messages of the chat screen when they are due.
Schedule::command('chats:send-scheduled')->everyMinute()->withoutOverlapping();
