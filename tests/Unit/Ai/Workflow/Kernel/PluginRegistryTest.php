<?php

use App\Ai\Workflow\Kernel\Contracts\AgentPlugin;
use App\Ai\Workflow\Kernel\Contracts\Plugin;
use App\Ai\Workflow\Kernel\Exceptions\PluginNotFoundException;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Kernel\PluginRegistry;
use Illuminate\Container\Container;
use Tests\TestCase;

uses(TestCase::class);

function makeFakePlugin(string $name, array $result = ['ok' => true]): AgentPlugin
{
    return new class($name, $result) implements AgentPlugin
    {
        public function __construct(
            private readonly string $name,
            private readonly array $result,
        ) {}

        public function getName(): string
        {
            return $this->name;
        }

        public function execute(array $input, KernelContext $context): array
        {
            return [
                'result' => $this->result,
                'meta' => ['plugin' => $this->name, 'type' => 'agent'],
            ];
        }
    };
}

test('register stores an instance and resolve returns it', function () {
    $registry = new PluginRegistry(new Container);
    $plugin = makeFakePlugin('chat_step');

    $registry->register('chat_step', $plugin);

    expect($registry->resolve('chat_step'))->toBe($plugin);
    expect($registry->has('chat_step'))->toBeTrue();
});

test('resolve throws when no binding exists', function () {
    $registry = new PluginRegistry(new Container);

    expect(fn () => $registry->resolve('missing'))
        ->toThrow(PluginNotFoundException::class, 'No plugin registered under name [missing].');
});

test('registerLazy resolves the class via the container on first use and caches it', function () {
    $container = new Container;
    $registry = new PluginRegistry($container);

    $container->bind('test.lazy.plugin', fn () => makeFakePlugin('lazy_plugin'));
    $registry->registerLazy('lazy_plugin', 'test.lazy.plugin');

    $first = $registry->resolve('lazy_plugin');
    $second = $registry->resolve('lazy_plugin');

    expect($first)->toBeInstanceOf(Plugin::class);
    expect($second)->toBe($first);
});

test('register replaces a prior lazy binding for the same name', function () {
    $container = new Container;
    $registry = new PluginRegistry($container);

    $container->bind('test.lazy.original', fn () => makeFakePlugin('first'));
    $registry->registerLazy('alpha', 'test.lazy.original');

    $replacement = makeFakePlugin('replacement');
    $registry->register('alpha', $replacement);

    expect($registry->resolve('alpha'))->toBe($replacement);
});

test('resolve rejects a lazy binding whose resolved object is not a Plugin', function () {
    $container = new Container;
    $registry = new PluginRegistry($container);

    // Bind to something that isn't a Plugin — simulates a typo in
    // KernelServiceProvider::PLUGINS or a class that stopped implementing
    // the contract. The registry must surface a clear PluginNotFoundException
    // instead of returning the wrong object and letting the Kernel
    // instanceof check fail downstream with a less helpful message.
    $container->bind('test.lazy.bad', fn () => new stdClass);
    $registry->registerLazy('bogus', 'test.lazy.bad');

    expect(fn () => $registry->resolve('bogus'))
        ->toThrow(PluginNotFoundException::class, 'No plugin registered under name [bogus].');
});

test('has returns false for names that were never registered', function () {
    $registry = new PluginRegistry(new Container);

    expect($registry->has('never_registered'))->toBeFalse();
});
