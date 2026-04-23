<?php

namespace App\Ai\Workflow\Kernel;

use App\Ai\Workflow\Kernel\Contracts\Plugin;
use App\Ai\Workflow\Kernel\Exceptions\PluginNotFoundException;
use Illuminate\Contracts\Container\Container;

/**
 * Name → plugin lookup that decouples the Kernel and plugin authors from
 * concrete class wiring. Two registration modes:
 *
 * - {@see register()} for already-instantiated plugins (useful in tests
 *   with anonymous-class fakes).
 * - {@see registerLazy()} for class names — the binding is resolved
 *   through the Laravel container on first use, so plugin dependencies
 *   are autowired and shared singletons stay singletons.
 *
 * The registry never owns plugin instances itself beyond caching the
 * lazy lookups — adding/removing plugins at runtime is fine.
 */
final class PluginRegistry
{
    /** @var array<string, Plugin> */
    private array $instances = [];

    /** @var array<string, class-string<Plugin>> */
    private array $lazy = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(string $name, Plugin $plugin): void
    {
        $this->instances[$name] = $plugin;
        unset($this->lazy[$name]);
    }

    /**
     * @param  class-string<Plugin>  $class
     */
    public function registerLazy(string $name, string $class): void
    {
        $this->lazy[$name] = $class;
        unset($this->instances[$name]);
    }

    public function resolve(string $name): Plugin
    {
        if (isset($this->instances[$name])) {
            return $this->instances[$name];
        }

        if (isset($this->lazy[$name])) {
            $plugin = $this->container->make($this->lazy[$name]);

            if (! $plugin instanceof Plugin) {
                throw PluginNotFoundException::for($name);
            }

            $this->instances[$name] = $plugin;

            return $plugin;
        }

        throw PluginNotFoundException::for($name);
    }

    public function has(string $name): bool
    {
        return isset($this->instances[$name]) || isset($this->lazy[$name]);
    }

    /**
     * @return array<string, class-string<Plugin>|Plugin>
     */
    public function all(): array
    {
        return [...$this->lazy, ...$this->instances];
    }
}
