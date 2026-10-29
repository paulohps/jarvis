<?php

use App\Services\Notifications\Types\ChatMessageReceived;
use App\Services\Notifications\Types\InvitationReceived;
use App\Services\Notifications\Types\InvoiceFailed;
use App\Services\Notifications\Types\InvoicePendingReview;
use App\Services\Notifications\Types\InvoiceSent;
use App\Services\Notifications\Types\ModuleEnabled;
use App\Services\Notifications\Types\ReminderDue;
use App\Services\Notifications\Types\WhatsappAccountDisconnected;
use App\Services\Notifications\Types\WhatsappMessageFailed;
use App\Services\Notifications\Types\WhatsappMessageReceived;

return [

    /*
    |--------------------------------------------------------------------------
    | Notification center types
    |--------------------------------------------------------------------------
    |
    | Every class the notification center may send (US-5.4). The registry
    | reads this list, `notifications:provision` creates the rows each one
    | needs and the super admin edits their texts.
    |
    */

    'types' => [
        InvitationReceived::class,
        ModuleEnabled::class,
        InvoiceSent::class,
        InvoiceFailed::class,
        InvoicePendingReview::class,
        WhatsappAccountDisconnected::class,
        WhatsappMessageFailed::class,
        WhatsappMessageReceived::class,
        ReminderDue::class,
        ChatMessageReceived::class,
    ],

];
