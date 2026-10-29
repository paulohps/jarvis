<?php

use App\Models\AiProviderConnection;
use App\Models\Device;
use App\Models\DevicePlatform;
use App\Models\Invitation;
use App\Models\Module;
use App\Models\NotificationPreference;
use App\Models\NotificationTemplate;
use App\Models\NotificationTypeChannel;
use App\Models\Recurrence;
use App\Models\RecurrenceFrequency;
use App\Models\SocialAccount;
use App\Models\SocialProvider;
use App\Models\User;
use App\Models\Weekday;
use Database\Factories\WorkspaceFactory;
use Illuminate\Database\Eloquent\Model;

test('core factories create valid records', function (string $modelClass) {
    /** @var class-string<Model> $modelClass */
    $records = $modelClass::factory()->count(3)->create();

    expect($records)->toHaveCount(3)
        ->and($modelClass::query()->count())->toBe(3)
        ->and($records->every(fn (Model $record) => $record->exists))->toBeTrue();
})->with([
    User::class,
    Invitation::class,
    SocialAccount::class,
    Device::class,
    AiProviderConnection::class,
    NotificationTypeChannel::class,
    NotificationTemplate::class,
    NotificationPreference::class,
    Recurrence::class,
]);

test('user factory states', function () {
    expect(User::factory()->create()->isSuperAdmin())->toBeFalse()
        ->and(User::factory()->superAdmin()->create()->isSuperAdmin())->toBeTrue()
        ->and(User::factory()->deactivated()->create()->isDeactivated())->toBeTrue();

    $withModules = User::factory()->withModules([Module::SHOPPING, Module::REMINDERS])->create();
    $googleOnly = User::factory()->googleOnly()->create();

    expect($withModules->membershipOf(WorkspaceFactory::household())->modules->pluck('slug')->all())->toEqualCanonicalizing(['shopping', 'reminders'])
        ->and($withModules->membershipOf(WorkspaceFactory::household())->is_default)->toBeTrue()
        ->and($withModules->hasModule(Module::INVOICES))->toBeFalse()
        ->and($googleOnly->password)->toBeNull()
        ->and($googleOnly->socialAccounts)->toHaveCount(1)
        ->and($googleOnly->socialAccounts->first()->provider->slug)->toBe(SocialProvider::GOOGLE)
        ->and($googleOnly->socialAccounts->first()->email)->toBe($googleOnly->email);
});

test('invitation factory states', function () {
    $accepted = Invitation::factory()->accepted()->create();

    expect(Invitation::factory()->create()->status)->toBe(Invitation::STATUS_PENDING)
        ->and(Invitation::factory()->expired()->create()->status)->toBe(Invitation::STATUS_EXPIRED)
        ->and(Invitation::factory()->revoked()->create()->status)->toBe(Invitation::STATUS_REVOKED)
        ->and($accepted->status)->toBe(Invitation::STATUS_ACCEPTED)
        ->and($accepted->acceptedUser->email)->toBe($accepted->email)
        ->and($accepted->invitedBy->isSuperAdmin())->toBeTrue();
});

test('device and ai provider connection factory states', function () {
    expect(Device::factory()->ios()->create()->platform->slug)->toBe(DevicePlatform::IOS)
        ->and(Device::factory()->android()->create()->platform->slug)->toBe(DevicePlatform::ANDROID)
        ->and(Device::factory()->create()->push_token)->toBeNull()
        ->and(Device::factory()->withPushToken()->create()->push_token)->not->toBeEmpty()
        ->and(AiProviderConnection::factory()->create()->is_active)->toBeFalse()
        ->and(AiProviderConnection::factory()->active()->create()->is_active)->toBeTrue();
});

test('recurrence factory states', function () {
    $once = Recurrence::factory()->once()->create();
    $weekly = Recurrence::factory()->weekly([Weekday::MONDAY, Weekday::THURSDAY])->create();
    $monthly = Recurrence::factory()->monthly(15)->create();
    $lastDay = Recurrence::factory()->lastDayOfMonth()->create();
    $cron = Recurrence::factory()->cron('30 8 * * *')->create();

    expect($once->frequency->slug)->toBe(RecurrenceFrequency::ONCE)
        ->and($once->next_run_at->equalTo($once->starts_at))->toBeTrue()
        ->and(Recurrence::factory()->daily()->create()->frequency->slug)->toBe(RecurrenceFrequency::DAILY)
        ->and($weekly->frequency->slug)->toBe(RecurrenceFrequency::WEEKLY)
        ->and($weekly->weekdays->pluck('slug')->all())->toEqualCanonicalizing(['monday', 'thursday'])
        ->and($monthly->frequency->slug)->toBe(RecurrenceFrequency::MONTHLY)
        ->and($monthly->day_of_month)->toBe(15)
        ->and($monthly->is_last_day_of_month)->toBeFalse()
        ->and($lastDay->frequency->slug)->toBe(RecurrenceFrequency::MONTHLY)
        ->and($lastDay->is_last_day_of_month)->toBeTrue()
        ->and($lastDay->day_of_month)->toBeNull()
        ->and($cron->frequency->slug)->toBe(RecurrenceFrequency::CUSTOM_CRON)
        ->and($cron->cron_expression)->toBe('30 8 * * *')
        ->and(Recurrence::factory()->paused()->create()->is_paused)->toBeTrue()
        ->and(Recurrence::factory()->create()->schedulable)->not->toBeNull();
});
