<?php

namespace App\Providers;

use App\Http\Middleware\EnsureModuleAccess;
use App\Http\Middleware\SetLocale;
use App\Models\ChatScheduledMessage;
use App\Models\InvoiceRecurrence;
use App\Models\Reminder;
use App\Models\ShoppingListSchedule;
use App\Models\SlackConversation;
use App\Models\User;
use App\Models\WhatsappMessage;
use App\Models\WhatsappTarget;
use App\Services\Mail\GmailTransport;
use App\Services\Notifications\NotificationGate;
use App\Support\LookupCache;
use App\Support\NotificationSettingsCache;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Foundation\DevCommands;
use Illuminate\Foundation\Events\DiagnosingHealth;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->scoped(LookupCache::class);
        $this->app->scoped(NotificationSettingsCache::class);
        $this->app->scoped(NotificationGate::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureDevCommands();
        $this->configureSchemaMacros();
        $this->configureMorphMap();
        $this->configureLivewire();
        $this->configureRateLimiting();
        $this->configureGates();
        $this->configureHealthCheck();
        $this->configureMailTransports();
    }

    /**
     * The `gmail` transport of the mail services connected to a Google
     * account (`MailServiceMailer`): the Gmail API with the account's refresh
     * token and the web client's credentials.
     */
    protected function configureMailTransports(): void
    {
        Mail::extend('gmail', fn (array $config): GmailTransport => new GmailTransport(
            refreshToken: (string) ($config['refresh_token'] ?? ''),
            clientId: (string) config('services.google.client_id'),
            clientSecret: (string) config('services.google.client_secret'),
        ));
    }

    /**
     * `/up` (the Dokploy healthcheck, US-12.4) answers 200 only when the
     * database answers too: a failing query throws and the route answers 500.
     */
    protected function configureHealthCheck(): void
    {
        Event::listen(DiagnosingHealth::class, function (): void {
            DB::select('select 1');
        });
    }

    /**
     * `super-admin` guards what only the super admin may do (the admin
     * area: notification types and templates, invitations, modules).
     * `access-module` lets a user into a module switched on for them (always
     * for the super admin), read fresh from `module_user` (US-2.1).
     */
    protected function configureGates(): void
    {
        Gate::define('super-admin', fn (User $user): bool => $user->isSuperAdmin());
        Gate::define('access-module', fn (User $user, string $moduleSlug): bool => $user->hasModule($moduleSlug));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }

    /**
     * Register the processes started by `composer dev` besides the framework
     * defaults (Horizon registers itself and replaces the queue listener):
     * Reverb and the scheduler.
     */
    protected function configureDevCommands(): void
    {
        DevCommands::artisan('reverb:start', 'reverb');
        DevCommands::artisan('schedule:work', 'schedule');
    }

    /**
     * `$table->friendlyId()` declares the public identifier the same way on
     * every exposed table, right after `id()`.
     */
    protected function configureSchemaMacros(): void
    {
        Blueprint::macro('friendlyId', function (string $column = 'friendly_id'): ColumnDefinition {
            /** @var Blueprint $this */
            return $this->string($column, 30)->unique();
        });
    }

    /**
     * Morph columns (`recurrences.schedulable_type`,
     * `notifications.notifiable_type`, `personal_access_tokens.tokenable_type`)
     * store short aliases, never class names. A model missing here throws
     * when it is used as a morph.
     */
    protected function configureMorphMap(): void
    {
        Relation::enforceMorphMap([
            'user' => User::class,
            'invoice_recurrence' => InvoiceRecurrence::class,
            'whatsapp_message' => WhatsappMessage::class,
            'reminder' => Reminder::class,
            'shopping_list_schedule' => ShoppingListSchedule::class,
            'slack_conversation' => SlackConversation::class,
            'whatsapp_target' => WhatsappTarget::class,
            'chat_scheduled_message' => ChatScheduledMessage::class,
        ]);
    }

    /**
     * Middleware re-applied to Livewire updates of a page that used it, so a
     * component update answers in the page's language and a module switched
     * off refuses the updates of its screens already open.
     */
    protected function configureLivewire(): void
    {
        Livewire::addPersistentMiddleware([
            SetLocale::class,
            EnsureModuleAccess::class,
        ]);
    }

    /**
     * The `api` limiter (US-12.3): 60 requests per minute per user, or per IP
     * for guests. The group throttle runs before `auth:sanctum`, so the token
     * user is read through the Sanctum guard.
     *
     * `api-auth` adds 10 sign-in attempts per minute per IP on the API sign-in
     * routes (US-1.5), on top of the lockout per e-mail and IP, so one client
     * cannot try many e-mails.
     *
     * `voice` adds 20 voice commands per minute per user (US-10.2): each
     * remote one is a paid call to the AI provider.
     *
     * `whatsapp-reply` adds 20 drafted WhatsApp replies per minute per user,
     * for the same reason; `chat-reply` the same for the chat screen.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', function (Request $request): Limit {
            $user = $request->user() ?? $request->user('sanctum');

            return Limit::perMinute(60)->by($user instanceof User ? 'user:'.$user->id : 'ip:'.$request->ip());
        });

        RateLimiter::for('api-auth', fn (Request $request): Limit => Limit::perMinute(10)->by('ip:'.$request->ip()));

        RateLimiter::for('voice', function (Request $request): Limit {
            $user = $request->user() ?? $request->user('sanctum');

            return Limit::perMinute(20)->by($user instanceof User ? 'voice:user:'.$user->id : 'voice:ip:'.$request->ip());
        });

        RateLimiter::for('whatsapp-reply', function (Request $request): Limit {
            $user = $request->user() ?? $request->user('sanctum');

            return Limit::perMinute(20)->by($user instanceof User ? 'whatsapp-reply:user:'.$user->id : 'whatsapp-reply:ip:'.$request->ip());
        });

        RateLimiter::for('chat-reply', function (Request $request): Limit {
            $user = $request->user() ?? $request->user('sanctum');

            return Limit::perMinute(20)->by($user instanceof User ? 'chat-reply:user:'.$user->id : 'chat-reply:ip:'.$request->ip());
        });
    }
}
