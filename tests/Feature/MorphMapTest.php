<?php

use App\Models\InvoiceRecurrence;
use App\Models\Reminder;
use App\Models\User;
use App\Models\WhatsappMessage;
use Illuminate\Database\ClassMorphViolationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\ScheduleProbe;

test('morph map stores short aliases', function () {
    Schema::create('schedule_probes', function (Blueprint $table) {
        $table->id();
        $table->morphs('schedulable');
        $table->timestamps();
    });

    $invoiceRecurrence = (new InvoiceRecurrence)->forceFill(['id' => 42]);

    $probe = new ScheduleProbe;
    $probe->schedulable()->associate($invoiceRecurrence);
    $probe->save();

    expect(DB::table('schedule_probes')->value('schedulable_type'))->toBe('invoice_recurrence')
        ->and(Model::getActualClassNameForMorph('invoice_recurrence'))->toBe(InvoiceRecurrence::class);
});

test('sanctum tokens store the user alias', function () {
    $user = User::factory()->create();

    $user->createToken('iPhone');

    expect(DB::table('personal_access_tokens')->value('tokenable_type'))->toBe('user');
});

test('every schedulable owner has its alias', function (string $model, string $alias) {
    expect((new $model)->getMorphClass())->toBe($alias);
})->with([
    [User::class, 'user'],
    [InvoiceRecurrence::class, 'invoice_recurrence'],
    [WhatsappMessage::class, 'whatsapp_message'],
    [Reminder::class, 'reminder'],
]);

test('a model outside the morph map cannot become a morph', function () {
    (new ScheduleProbe)->getMorphClass();
})->throws(ClassMorphViolationException::class);
