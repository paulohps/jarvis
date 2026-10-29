<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use PHPUnit\Framework\Assert;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/*
|--------------------------------------------------------------------------
| Islands
|--------------------------------------------------------------------------
|
| A render of a component skips its islands: what an island shows after an
| action is in the fragments the action rendered, not in the component's
| HTML.
|
*/

Testable::macro('assertSeeInIsland', function (string $html): Testable {
    /** @var Testable $this */
    Assert::assertStringContainsString($html, implode('', $this->effects['islandFragments'] ?? []));

    return $this;
});

Testable::macro('assertDontSeeInIsland', function (string $html): Testable {
    /** @var Testable $this */
    Assert::assertNotEmpty($this->effects['islandFragments'] ?? [], 'No island rendered.');
    Assert::assertStringNotContainsString($html, implode('', $this->effects['islandFragments']));

    return $this;
});
