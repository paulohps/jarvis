<?php

use App\Support\FriendlyId;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FriendlyRecord;

beforeEach(function () {
    Schema::create('friendly_records', function (Blueprint $table) {
        $table->id();
        $table->friendlyId();
        $table->string('name');
        $table->timestamps();
    });

    Route::middleware('web')->get('/friendly-records/{record}', fn (FriendlyRecord $record) => $record->name);
});

test('friendly id is generated on create and is unique', function () {
    $records = collect(range(1, 50))->map(fn (int $index) => FriendlyRecord::create(['name' => "record {$index}"]));

    $friendlyIds = $records->pluck('friendly_id');

    expect($friendlyIds->unique())->toHaveCount(50)
        ->and($friendlyIds->every(fn (string $id) => strlen($id) <= FriendlyId::MAX_LENGTH))->toBeTrue()
        ->and($friendlyIds->every(fn (string $id) => FriendlyId::isValid($id, 'frec')))->toBeTrue();
});

test('friendly id column is unique at the database level', function () {
    $record = FriendlyRecord::create(['name' => 'first']);

    FriendlyRecord::create(['name' => 'second', 'friendly_id' => $record->friendly_id]);
})->throws(UniqueConstraintViolationException::class);

test('a preset friendly id is kept', function () {
    $record = FriendlyRecord::create(['name' => 'preset', 'friendly_id' => 'frec_preset']);

    expect($record->friendly_id)->toBe('frec_preset');
});

test('route binding resolves by friendly id only', function () {
    $record = FriendlyRecord::create(['name' => 'bound']);

    $this->get("/friendly-records/{$record->friendly_id}")
        ->assertOk()
        ->assertSee('bound');

    $this->get("/friendly-records/{$record->id}")->assertNotFound();
});
