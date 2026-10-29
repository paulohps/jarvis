<?php

use App\Http\Controllers\Api\V1\Admin\InvitationController as AdminInvitationController;
use App\Http\Controllers\Api\V1\Admin\ModuleController as AdminModuleController;
use App\Http\Controllers\Api\V1\Admin\UserController as AdminUserController;
use App\Http\Controllers\Api\V1\Admin\WorkspaceController as AdminWorkspaceController;
use App\Http\Controllers\Api\V1\Admin\WorkspaceMemberController as AdminWorkspaceMemberController;
use App\Http\Controllers\Api\V1\Agenda\AgendaController;
use App\Http\Controllers\Api\V1\Auth\GoogleLoginController;
use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Chats\ChatContactController;
use App\Http\Controllers\Api\V1\Chats\ChatConversationController;
use App\Http\Controllers\Api\V1\Chats\ChatMessageController;
use App\Http\Controllers\Api\V1\Chats\ChatProviderController;
use App\Http\Controllers\Api\V1\Chats\ChatScheduledMessageController;
use App\Http\Controllers\Api\V1\Chats\ChatTagController;
use App\Http\Controllers\Api\V1\DeviceController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\Reminders\ReminderController;
use App\Http\Controllers\Api\V1\Reminders\ReminderOccurrenceController;
use App\Http\Controllers\Api\V1\Shopping\ShoppingListController;
use App\Http\Controllers\Api\V1\Shopping\ShoppingListItemController;
use App\Http\Controllers\Api\V1\Shopping\ShoppingListScheduleController;
use App\Http\Controllers\Api\V1\VoiceCommandController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappAccountController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappChatController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappChatMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappChatSettingsController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappConversationController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappDeliveryController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappGroupController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappMessageController;
use App\Http\Controllers\Api\V1\Whatsapp\WhatsappTagController;
use App\Http\Controllers\Chats\ChatFileController;
use App\Http\Controllers\Webhooks\EvolutionWebhookController;
use App\Http\Controllers\Webhooks\SlackWebhookController;
use App\Http\Controllers\Whatsapp\WhatsappChatMediaController;
use App\Http\Middleware\VerifyEvolutionWebhookSecret;
use App\Http\Middleware\VerifySlackSignature;
use App\Models\Module;
use Illuminate\Broadcasting\BroadcastController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API v1
|--------------------------------------------------------------------------
|
| Every route here is served under `/api/v1` (bootstrap/app.php), throttled
| by the `api` limiter and answered in JSON, errors included. Each module
| phase registers its endpoints here, calling the same Actions as Livewire.
|
*/

// The Evolution API webhooks (US-7.1, US-7.2), authenticated by the shared secret.
Route::post('/webhooks/evolution', EvolutionWebhookController::class)
    ->middleware(VerifyEvolutionWebhookSecret::class)
    ->name('webhooks.evolution');

// The Events API of the Slack app (the chat screen), behind its signature.
Route::post('/webhooks/slack', SlackWebhookController::class)
    ->middleware(VerifySlackSignature::class)
    ->name('webhooks.slack');

// A file of a message of the chat screen, for the web and the apps alike: a
// temporary signed URL the channel's driver puts in the message, no token.
Route::get('/chats/files/{conversation}/{message}/{file}', ChatFileController::class)
    ->middleware(['signed', 'throttle:api'])
    ->name('api.chats.file');

// The file of a WhatsApp chat media for the apps' players and images: a
// temporary signed URL (in WhatsappConversationMessageResource), no token.
Route::get('/whatsapp/media/{message}', WhatsappChatMediaController::class)
    ->middleware(['signed', 'throttle:api'])
    ->name('api.whatsapp.media');

// App sign-in (password or native Google) and sign-out, a token per device (US-1.5).
Route::prefix('auth')->name('api.auth.')->group(function () {
    Route::post('/login', LoginController::class)->middleware('throttle:api-auth')->name('login');
    Route::post('/google', GoogleLoginController::class)->middleware('throttle:api-auth')->name('google');
    Route::post('/logout', LogoutController::class)->middleware('auth:sanctum')->name('logout');
});

Route::middleware('auth:sanctum')->name('api.')->group(function () {
    // The signed-in user, their modules and preferences (US-1.5, US-3.2, US-11.1).
    Route::get('/me', [MeController::class, 'show'])->name('me');
    Route::patch('/me/preferences', [MeController::class, 'updatePreferences'])->name('me.preferences');

    // The user's own view of their workspaces: their color and default.
    Route::patch('/me/workspaces/{workspace}', [MeController::class, 'updateWorkspace'])->name('me.workspaces.update');
    Route::get('/workspace-colors', [MeController::class, 'workspaceColors'])->name('workspace-colors');

    // The super admin's area: workspaces and their members, users,
    // invitations and the modules to switch on.
    Route::middleware('can:super-admin')->prefix('admin')->name('admin.')->group(function () {
        Route::apiResource('workspaces', AdminWorkspaceController::class);
        Route::post('/workspaces/{workspace}/members', [AdminWorkspaceMemberController::class, 'store'])->name('workspaces.members.store');
        Route::patch('/workspaces/{workspace}/members/{user}', [AdminWorkspaceMemberController::class, 'update'])->name('workspaces.members.update');
        Route::delete('/workspaces/{workspace}/members/{user}', [AdminWorkspaceMemberController::class, 'destroy'])->name('workspaces.members.destroy');
        Route::get('/users', [AdminUserController::class, 'index'])->name('users.index');
        Route::post('/users/{user}/deactivate', [AdminUserController::class, 'deactivate'])->name('users.deactivate');
        Route::post('/users/{user}/reactivate', [AdminUserController::class, 'reactivate'])->name('users.reactivate');
        Route::get('/invitations', [AdminInvitationController::class, 'index'])->name('invitations.index');
        Route::post('/invitations', [AdminInvitationController::class, 'store'])->name('invitations.store');
        Route::post('/invitations/{invitation}/resend', [AdminInvitationController::class, 'resend'])->name('invitations.resend');
        Route::post('/invitations/{invitation}/revoke', [AdminInvitationController::class, 'revoke'])->name('invitations.revoke');
        Route::get('/modules', [AdminModuleController::class, 'index'])->name('modules.index');
    });

    // The devices signed in to the app, for push (US-3.4, US-5.2).
    Route::apiResource('devices', DeviceController::class)->only(['index', 'store', 'destroy']);

    // The in-app notifications (US-5.1).
    Route::get('/notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('/notifications/read-all', [NotificationController::class, 'readAll'])->name('notifications.read-all');
    Route::post('/notifications/{notification}/read', [NotificationController::class, 'read'])->name('notifications.read');

    // The apps sign the private Reverb channels with their token (US-5.1, US-8.2),
    // under the same rules as the web (routes/channels.php).
    Route::post('/broadcasting/auth', [BroadcastController::class, 'authenticate'])->name('broadcasting.auth');

    // The shopping module (US-8.1, US-8.2): lists shared by the members of their workspace.
    Route::middleware('module:'.Module::SHOPPING)->prefix('shopping')->name('shopping.')->group(function () {
        Route::apiResource('lists', ShoppingListController::class);
        Route::post('/lists/{list}/archive', [ShoppingListController::class, 'archive'])->name('lists.archive');
        Route::post('/lists/{list}/complete', [ShoppingListController::class, 'complete'])->name('lists.complete');
        Route::post('/lists/{list}/unarchive', [ShoppingListController::class, 'unarchive'])->name('lists.unarchive');
        Route::post('/lists/{list}/default', [ShoppingListController::class, 'makeDefault'])->name('lists.default');
        Route::delete('/lists/{list}/default', [ShoppingListController::class, 'unsetDefault'])->name('lists.default.destroy');
        Route::post('/lists/{list}/clear-checked', [ShoppingListController::class, 'clearChecked'])->name('lists.clear-checked');
        Route::post('/lists/{list}/reorder', [ShoppingListController::class, 'reorder'])->name('lists.reorder');
        Route::post('/lists/{list}/items', [ShoppingListItemController::class, 'store'])->name('lists.items.store');
        Route::apiResource('items', ShoppingListItemController::class)->only(['update', 'destroy']);
        Route::post('/items/{item}/toggle', [ShoppingListItemController::class, 'toggle'])->name('items.toggle');
        Route::apiResource('schedules', ShoppingListScheduleController::class);
    });

    // The reminders module (US-9.1): shared by the members of each workspace.
    Route::middleware('module:'.Module::REMINDERS)->group(function () {
        Route::get('/agenda', [AgendaController::class, 'index'])->name('agenda.index');
        Route::get('/reminders/agenda', [ReminderController::class, 'agenda'])->name('reminders.agenda');
        Route::apiResource('reminders', ReminderController::class);
        Route::post('/reminder-occurrences/{occurrence}/complete', [ReminderOccurrenceController::class, 'complete'])->name('reminder-occurrences.complete');
        Route::post('/reminder-occurrences/{occurrence}/reopen', [ReminderOccurrenceController::class, 'reopen'])->name('reminder-occurrences.reopen');
    });

    // The WhatsApp module (US-7.3, US-7.5): each user's own accounts,
    // messages and history. Connecting a number by QR code is web only.
    Route::middleware('module:'.Module::WHATSAPP)->prefix('whatsapp')->name('whatsapp.')->group(function () {
        Route::get('/accounts', [WhatsappAccountController::class, 'index'])->name('accounts.index');
        Route::get('/accounts/{account}/targets', [WhatsappAccountController::class, 'targets'])->name('accounts.targets');
        Route::apiResource('messages', WhatsappMessageController::class);
        Route::post('/messages/{message}/cancel', [WhatsappMessageController::class, 'cancel'])->name('messages.cancel');
        Route::post('/messages/{message}/pause', [WhatsappMessageController::class, 'pause'])->name('messages.pause');
        Route::post('/messages/{message}/resume', [WhatsappMessageController::class, 'resume'])->name('messages.resume');
        Route::post('/send-now', [WhatsappDeliveryController::class, 'sendNow'])->name('send-now');
        Route::get('/deliveries', [WhatsappDeliveryController::class, 'index'])->name('deliveries.index');

        // The reply keyboard: chats latest received first, and an answer
        // drafted by the AI on demand (20 per minute per user).
        Route::get('/chats', [WhatsappChatController::class, 'index'])->name('chats.index');
        Route::get('/chats/{chat}/messages', [WhatsappChatController::class, 'messages'])->name('chats.messages');
        Route::post('/chat-messages/{message}/reply', [WhatsappChatController::class, 'reply'])->middleware('throttle:whatsapp-reply')->name('chat-messages.reply');

        // The WhatsApp screen of the apps: every chat, its messages and
        // files, sending, reading and "typing…".
        Route::get('/conversations', [WhatsappConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{chat}/messages', [WhatsappConversationController::class, 'messages'])->name('conversations.messages');
        Route::post('/conversations/{chat}/messages', [WhatsappConversationController::class, 'send'])->name('conversations.send');
        Route::post('/conversations/{chat}/read', [WhatsappConversationController::class, 'read'])->name('conversations.read');
        Route::post('/conversations/{chat}/typing', [WhatsappConversationController::class, 'typing'])->middleware('throttle:30,1')->name('conversations.typing');

        // The chat tools of the web: details and settings of a chat,
        // starting one, syncing it, the AI draft, reminders, scheduling,
        // tags, priority colors and the defaults of each number's chats.
        Route::post('/conversations', [WhatsappConversationController::class, 'start'])->name('conversations.start');
        Route::get('/conversations/{chat}', [WhatsappConversationController::class, 'show'])->name('conversations.show');
        Route::patch('/conversations/{chat}', [WhatsappConversationController::class, 'update'])->name('conversations.update');
        Route::post('/conversations/{chat}/sync', [WhatsappConversationController::class, 'sync'])->middleware('throttle:10,1')->name('conversations.sync');
        Route::post('/conversations/{chat}/draft', [WhatsappConversationController::class, 'draft'])->middleware('throttle:whatsapp-reply')->name('conversations.draft');
        Route::post('/conversations/{chat}/reminders', [WhatsappConversationController::class, 'remind'])->name('conversations.remind');
        Route::post('/conversations/{chat}/schedule', [WhatsappConversationController::class, 'schedule'])->name('conversations.schedule');
        Route::post('/conversations/{chat}/messages/{message}/reaction', [WhatsappConversationController::class, 'react'])->middleware('throttle:60,1')->name('conversations.react');
        Route::post('/conversations/{chat}/forward', [WhatsappConversationController::class, 'forward'])->middleware('throttle:20,1')->name('conversations.forward');

        // Finding and handling one message: search, starred, gallery.
        Route::get('/conversations/{chat}/search', [WhatsappChatMessageController::class, 'search'])->name('conversations.search');
        Route::get('/conversations/{chat}/starred', [WhatsappChatMessageController::class, 'starred'])->name('conversations.starred');
        Route::get('/conversations/{chat}/gallery', [WhatsappChatMessageController::class, 'gallery'])->name('conversations.gallery');
        Route::post('/conversations/{chat}/messages/{message}/star', [WhatsappChatMessageController::class, 'star'])->name('conversations.star');
        Route::patch('/conversations/{chat}/messages/{message}', [WhatsappChatMessageController::class, 'update'])->middleware('throttle:30,1')->name('conversations.edit');
        Route::delete('/conversations/{chat}/messages/{message}', [WhatsappChatMessageController::class, 'destroy'])->middleware('throttle:30,1')->name('conversations.delete');
        Route::get('/conversations/{chat}/messages/{message}/info', [WhatsappChatMessageController::class, 'info'])->name('conversations.info');
        Route::post('/conversations/{chat}/poll', [WhatsappChatMessageController::class, 'poll'])->name('conversations.poll');
        Route::post('/conversations/{chat}/location', [WhatsappChatMessageController::class, 'location'])->name('conversations.location');
        Route::post('/conversations/{chat}/contacts', [WhatsappChatMessageController::class, 'contacts'])->name('conversations.contacts');
        Route::post('/conversations/{chat}/sticker', [WhatsappChatMessageController::class, 'sticker'])->name('conversations.sticker');
        Route::post('/groups', [WhatsappGroupController::class, 'store'])->middleware('throttle:10,1')->name('groups.store');
        Route::get('/conversations/{chat}/group', [WhatsappGroupController::class, 'show'])->name('conversations.group');
        Route::patch('/conversations/{chat}/group', [WhatsappGroupController::class, 'update'])->middleware('throttle:30,1')->name('conversations.group.update');
        Route::post('/conversations/{chat}/group/picture', [WhatsappGroupController::class, 'picture'])->middleware('throttle:10,1')->name('conversations.group.picture');
        Route::post('/conversations/{chat}/group/members', [WhatsappGroupController::class, 'members'])->middleware('throttle:30,1')->name('conversations.group.members');
        Route::post('/conversations/{chat}/group/invite', [WhatsappGroupController::class, 'invite'])->middleware('throttle:10,1')->name('conversations.group.invite');
        Route::post('/conversations/{chat}/group/leave', [WhatsappGroupController::class, 'leave'])->name('conversations.group.leave');
        Route::apiResource('tags', WhatsappTagController::class)->except('show');
        Route::get('/priority-colors', [WhatsappChatSettingsController::class, 'priorityColors'])->name('priority-colors.show');
        Route::put('/priority-colors', [WhatsappChatSettingsController::class, 'savePriorityColors'])->name('priority-colors.update');
        Route::patch('/chat-settings/{account}', [WhatsappChatSettingsController::class, 'updateAccount'])->name('chat-settings.update');
    });

    // The chats module: every channel in one chat screen — the channels
    // and accounts, the conversations with what each channel supports,
    // their messages, tags, contacts and scheduled messages.
    Route::middleware('module:'.Module::CHATS)->prefix('chats')->name('chats.')->group(function () {
        Route::get('/providers', [ChatProviderController::class, 'index'])->name('providers.index');
        Route::get('/conversations', [ChatConversationController::class, 'index'])->name('conversations.index');
        Route::get('/conversations/{conversation}', [ChatConversationController::class, 'show'])->name('conversations.show');
        Route::patch('/conversations/{conversation}', [ChatConversationController::class, 'update'])->name('conversations.update');
        Route::post('/conversations/{conversation}/read', [ChatConversationController::class, 'read'])->name('conversations.read');
        Route::post('/conversations/{conversation}/sync', [ChatConversationController::class, 'sync'])->middleware('throttle:10,1')->name('conversations.sync');
        Route::post('/conversations/{conversation}/draft', [ChatConversationController::class, 'draft'])->middleware('throttle:chat-reply')->name('conversations.draft');
        Route::post('/conversations/{conversation}/reminders', [ChatConversationController::class, 'remind'])->name('conversations.remind');
        Route::post('/conversations/{conversation}/schedule', [ChatConversationController::class, 'schedule'])->name('conversations.schedule');
        Route::post('/conversations/{conversation}/contact', [ChatConversationController::class, 'link'])->name('conversations.link');
        Route::delete('/conversations/{conversation}/contact', [ChatConversationController::class, 'unlink'])->name('conversations.unlink');
        Route::get('/conversations/{conversation}/messages', [ChatMessageController::class, 'index'])->name('conversations.messages');
        Route::post('/conversations/{conversation}/messages', [ChatMessageController::class, 'store'])->name('conversations.send');
        Route::get('/conversations/{conversation}/messages/{message}/thread', [ChatMessageController::class, 'thread'])->name('conversations.thread');
        Route::patch('/conversations/{conversation}/messages/{message}', [ChatMessageController::class, 'update'])->middleware('throttle:30,1')->name('conversations.edit');
        Route::delete('/conversations/{conversation}/messages/{message}', [ChatMessageController::class, 'destroy'])->middleware('throttle:30,1')->name('conversations.delete');
        Route::post('/conversations/{conversation}/messages/{message}/reaction', [ChatMessageController::class, 'react'])->middleware('throttle:60,1')->name('conversations.react');
        Route::apiResource('tags', ChatTagController::class)->except('show');
        Route::get('/contacts', [ChatContactController::class, 'index'])->name('contacts.index');
        Route::patch('/contacts/{contact}', [ChatContactController::class, 'update'])->name('contacts.update');
        Route::delete('/scheduled/{scheduled}', [ChatScheduledMessageController::class, 'destroy'])->name('scheduled.destroy');
    });

    // Voice commands (US-10.2, US-10.3): the remote interpreter, the actions
    // structured by the app's own and the confirmation of a WhatsApp send.
    // 20 per minute per user, on top of the `api` limiter.
    Route::middleware('throttle:voice')->prefix('voice')->name('voice.')->group(function () {
        Route::post('/commands', [VoiceCommandController::class, 'interpret'])->name('commands');
        Route::post('/actions', [VoiceCommandController::class, 'execute'])->name('actions');
        Route::post('/commands/confirm', [VoiceCommandController::class, 'confirm'])->middleware('module:'.Module::WHATSAPP)->name('commands.confirm');
    });
});
