<?php

use App\Http\Controllers\Agenda\GoogleCalendarConnectCallbackController;
use App\Http\Controllers\Agenda\GoogleCalendarConnectRedirectController;
use App\Http\Controllers\Auth\GoogleCallbackController;
use App\Http\Controllers\Auth\GoogleRedirectController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Chats\ChatFileController;
use App\Http\Controllers\Chats\SlackConnectCallbackController;
use App\Http\Controllers\Chats\SlackConnectRedirectController;
use App\Http\Controllers\Invoices\InvoicePdfController;
use App\Http\Controllers\Invoices\InvoiceRecurrencePreviewPdfController;
use App\Http\Controllers\Mail\GmailConnectCallbackController;
use App\Http\Controllers\Mail\GmailConnectRedirectController;
use App\Http\Controllers\Notifications\OpenNotificationController;
use App\Http\Controllers\Push\WebPushSubscriptionController;
use App\Http\Controllers\Pwa\ServiceWorkerController;
use App\Http\Controllers\Pwa\WebManifestController;
use App\Http\Controllers\SwitchLocaleController;
use App\Http\Controllers\Whatsapp\WhatsappChatMediaController;
use App\Livewire\Admin\AdminHome;
use App\Livewire\Admin\Ai\AiProviderList;
use App\Livewire\Admin\Ai\AiUsageReport;
use App\Livewire\Admin\Invitations\InvitationList;
use App\Livewire\Admin\Notifications\NotificationTemplateEditor;
use App\Livewire\Admin\Notifications\NotificationTypeList;
use App\Livewire\Admin\Users\UserList;
use App\Livewire\Admin\Workspaces\WorkspaceDetail;
use App\Livewire\Admin\Workspaces\WorkspaceList;
use App\Livewire\Agenda\AgendaPage;
use App\Livewire\Auth\AcceptInvitationForm;
use App\Livewire\Auth\ForgotPassword;
use App\Livewire\Auth\Login;
use App\Livewire\Auth\ResetPasswordForm;
use App\Livewire\Chats\ChatChannelList;
use App\Livewire\Chats\ChatsPage;
use App\Livewire\Chats\ScheduledMessages;
use App\Livewire\Chats\SendingHistory;
use App\Livewire\Dashboard;
use App\Livewire\Invoices\InvoiceHistory;
use App\Livewire\Invoices\InvoiceRecurrenceForm;
use App\Livewire\Invoices\InvoiceRecurrenceList;
use App\Livewire\Invoices\InvoiceReview;
use App\Livewire\Invoices\IssuerList;
use App\Livewire\Invoices\PayerList;
use App\Livewire\Mail\MailServiceList;
use App\Livewire\Notifications\NotificationHistory;
use App\Livewire\Profile\ProfilePage;
use App\Livewire\Shopping\ShoppingListIndex;
use App\Livewire\Shopping\ShoppingListShow;
use App\Livewire\Whatsapp\AccountList;
use App\Livewire\Whatsapp\Chat;
use App\Livewire\Whatsapp\ConnectAccount;
use App\Livewire\Whatsapp\DeliveryHistory;
use App\Livewire\Whatsapp\MessageForm;
use App\Livewire\Whatsapp\MessageList;
use App\Livewire\Whatsapp\TargetList;
use App\Models\Module;
use Illuminate\Support\Facades\Route;

Route::get('/locale/{locale}', SwitchLocaleController::class)->name('locale.update');

// Public for everyone, signed in or not: Google links to them from its consent screen.
Route::view('/privacy', 'legal.privacy')->name('legal.privacy');
Route::view('/terms', 'legal.terms')->name('legal.terms');

// The PWA (US-12.2): the manifest and the service worker are read by the
// browser before anyone signs in, and the offline page is what it shows
// when there is no connection.
Route::get('/manifest.webmanifest', WebManifestController::class)->name('pwa.manifest');
Route::get('/sw.js', ServiceWorkerController::class)->name('pwa.service-worker');
Route::view('/offline', 'pwa.offline')->name('pwa.offline');

// There is no sign-up: accounts only come from invitations (US-1.1).
Route::middleware('guest')->group(function (): void {
    Route::livewire('/login', Login::class)->name('login');
    Route::livewire('/forgot-password', ForgotPassword::class)->name('password.request');
    Route::livewire('/reset-password/{token}', ResetPasswordForm::class)->name('password.reset');
    Route::livewire('/invitations/{token}', AcceptInvitationForm::class)->name('invitations.accept');

    Route::get('/auth/google/redirect', GoogleRedirectController::class)->name('auth.google.redirect');
    Route::get('/auth/google/callback', GoogleCallbackController::class)->name('auth.google.callback');
});

Route::post('/logout', LogoutController::class)->middleware('auth')->name('logout');

Route::middleware('auth')->group(function (): void {
    Route::livewire('/', Dashboard::class)->name('dashboard');

    Route::livewire('/profile', ProfilePage::class)->name('profile');

    // The browser's push registration of the web app (US-5.2).
    Route::post('/push/subscriptions', [WebPushSubscriptionController::class, 'store'])->name('push.subscriptions.store');
    Route::delete('/push/subscriptions/{device:friendly_id}', [WebPushSubscriptionController::class, 'destroy'])->name('push.subscriptions.destroy');

    Route::livewire('/mail-services', MailServiceList::class)->name('mail-services.index');
    Route::get('/mail-services/google/redirect', GmailConnectRedirectController::class)->name('mail-services.google.redirect');
    Route::get('/mail-services/google/callback', GmailConnectCallbackController::class)->name('mail-services.google.callback');

    Route::livewire('/notifications', NotificationHistory::class)->name('notifications.index');
    Route::get('/notifications/{notification}/open', OpenNotificationController::class)
        ->whereUuid('notification')
        ->name('notifications.open');

    // The invoices module (US-6.1 to US-6.8), web only.
    Route::middleware('module:'.Module::INVOICES)->prefix('invoices')->name('invoices.')->group(function (): void {
        Route::livewire('/', InvoiceRecurrenceList::class)->name('index');
        Route::livewire('/payers', PayerList::class)->name('payers.index');
        Route::livewire('/issuers', IssuerList::class)->name('issuers.index');
        Route::livewire('/recurrences/create', InvoiceRecurrenceForm::class)->name('recurrences.create');
        Route::livewire('/recurrences/{invoiceRecurrence}', InvoiceRecurrenceForm::class)->name('recurrences.edit');
        Route::get('/recurrences/{invoiceRecurrence}/preview.pdf', InvoiceRecurrencePreviewPdfController::class)->name('recurrences.preview-pdf');
        Route::livewire('/history', InvoiceHistory::class)->name('history.index');
        Route::livewire('/history/{invoice}', InvoiceReview::class)->name('history.show');
        Route::get('/history/{invoice}/pdf', InvoicePdfController::class)->name('history.pdf');
    });

    // The WhatsApp module (US-7.1 to US-7.5): each user's own accounts,
    // messages and history.
    Route::middleware('module:'.Module::WHATSAPP)->prefix('whatsapp')->name('whatsapp.')->group(function (): void {
        Route::redirect('/', '/whatsapp/accounts')->name('index');
        Route::livewire('/accounts', AccountList::class)->name('accounts.index');
        Route::livewire('/accounts/connect', ConnectAccount::class)->name('accounts.connect');
        Route::livewire('/accounts/{account}/connect', ConnectAccount::class)->name('accounts.reconnect');
        Route::livewire('/accounts/{account}/targets', TargetList::class)->name('accounts.targets');
        Route::livewire('/messages', MessageList::class)->name('messages.index');
        Route::livewire('/messages/create', MessageForm::class)->name('messages.create');
        Route::livewire('/messages/{message}', MessageForm::class)->name('messages.edit');
        Route::livewire('/history', DeliveryHistory::class)->name('history.index');
        Route::livewire('/chat', Chat::class)->name('chat');
        Route::get('/chat/media/{message}', WhatsappChatMediaController::class)->name('chat.media');
    });

    // The chats module: every channel (Slack for now) in one chat screen,
    // each user's own accounts and conversations.
    Route::middleware('module:'.Module::CHATS)->prefix('chats')->name('chats.')->group(function (): void {
        Route::livewire('/', ChatChannelList::class)->name('index');
        Route::livewire('/chat', ChatsPage::class)->name('chat');
        Route::livewire('/scheduled', ScheduledMessages::class)->name('scheduled');
        Route::livewire('/history', SendingHistory::class)->name('history');
        Route::get('/slack/redirect', SlackConnectRedirectController::class)->name('slack.redirect');
        Route::get('/slack/callback', SlackConnectCallbackController::class)->name('slack.callback');
        Route::get('/{conversation}/messages/{message}/files/{file}', ChatFileController::class)->name('file');
    });

    // The shopping module (US-8.1, US-8.2): lists shared by the members of their workspace.
    Route::middleware('module:'.Module::SHOPPING)->prefix('shopping')->name('shopping.')->group(function (): void {
        Route::livewire('/', ShoppingListIndex::class)->name('index');
        Route::livewire('/lists/{list}', ShoppingListShow::class)->name('show');
    });

    // The agenda module (US-9.1): shared per workspace. Its slug and
    // route names are still `reminders`, which the apps and links know.
    Route::middleware('module:'.Module::REMINDERS)->prefix('agenda')->name('reminders.')->group(function (): void {
        Route::livewire('/', AgendaPage::class)->name('index');
        Route::get('/google/redirect', GoogleCalendarConnectRedirectController::class)->name('google.redirect');
        Route::get('/google/callback', GoogleCalendarConnectCallbackController::class)->name('google.callback');
    });
    Route::redirect('/reminders', '/agenda');

    // The admin area (super admin only).
    Route::middleware('can:super-admin')->prefix('admin')->name('admin.')->group(function (): void {
        Route::livewire('/', AdminHome::class)->name('index');
        Route::livewire('/users', UserList::class)->name('users.index');
        Route::livewire('/invitations', InvitationList::class)->name('invitations.index');
        Route::livewire('/workspaces', WorkspaceList::class)->name('workspaces.index');
        Route::livewire('/workspaces/{workspace}', WorkspaceDetail::class)->name('workspaces.show');
        // Module access is set per workspace member now.
        Route::redirect('/modules', '/admin/workspaces')->name('modules.index');
        Route::livewire('/ai-providers', AiProviderList::class)->name('ai-providers.index');
        Route::livewire('/ai-usage', AiUsageReport::class)->name('ai-usage.index');
        Route::livewire('/notifications', NotificationTypeList::class)->name('notifications.index');
        Route::livewire('/notifications/{notificationType:slug}', NotificationTemplateEditor::class)->name('notifications.edit');
    });
});

// Unknown pages go through the web middleware, so the 404 page is written
// in the reader's language.
Route::fallback(fn () => abort(404));
