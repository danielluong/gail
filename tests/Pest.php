<?php

use App\Providers\AiServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

pest()->extend(TestCase::class)->in('Unit/Services', 'Unit/Http', 'Unit/Support');

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

/**
 * Reset the ai.tools.* container tags and re-register AiServiceProvider
 * so provider-time config checks (like ai.default_for_images) re-run
 * against the current config state.
 */
function reregisterAiServiceProvider(): void
{
    $app = app();

    $property = (new ReflectionClass($app))->getProperty('tags');
    $tags = $property->getValue($app);
    unset(
        $tags['ai.tools.chat'],
        $tags['ai.tools.limerick'],
        $tags['ai.tools.mysql_database'],
        $tags['ai.context_providers'],
    );
    $property->setValue($app, $tags);

    (new AiServiceProvider($app))->register();
}
