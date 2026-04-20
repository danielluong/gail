<?php

/*
 * Architecture rules that enforce the layering described in docs/architecture.md §1.
 *
 * Each rule encodes one dependency-direction invariant: "layer X must not
 * reach into layer Y." When a new class would break the rule, the test fails
 * at PR time — cheap insurance against drift back into fat-controller /
 * model-knows-about-actions patterns.
 */

arch('models are leaf — they depend only on framework and each other')
    ->expect('App\Models')
    ->not->toUse(['App\Http', 'App\Actions', 'App\Ai', 'App\Jobs']);

arch('tools do not know about controllers or application actions')
    ->expect('App\Ai\Tools')
    ->not->toUse(['App\Http', 'App\Actions']);

arch('context providers do not know about controllers or application actions')
    ->expect('App\Ai\Context')
    ->not->toUse(['App\Http', 'App\Actions']);

arch('controllers do not reach into concrete tools — they go through agents')
    ->expect('App\Http\Controllers')
    ->not->toUse('App\Ai\Tools');

arch('form requests do not orchestrate application logic')
    ->expect('App\Http\Requests')
    ->not->toUse(['App\Actions'])
    ->ignoring('App\Ai\Agents\AgentType');

arch('services are framework-edge wrappers — they do not depend on controllers or actions')
    ->expect('App\Services')
    ->not->toUse(['App\Http', 'App\Actions']);

arch('storage wrappers live below the application layer')
    ->expect('App\Ai\Storage')
    ->not->toUse(['App\Http', 'App\Actions']);

arch('enums are pure value types')
    ->expect('App\Enums')
    ->not->toUse(['App\Http', 'App\Actions', 'App\Services', 'App\Ai']);

arch('no debug helpers left behind in application code')
    ->expect(['dd', 'dump', 'ray', 'var_dump'])
    ->not->toBeUsed();
