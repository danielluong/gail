<?php

namespace App\Ai\Workflow\Kernel\Exceptions;

use App\Ai\Workflow\Kernel\PluginRegistry;
use App\Providers\KernelServiceProvider;
use RuntimeException;

/**
 * Raised by {@see PluginRegistry::resolve()} when
 * the requested plugin name has no binding. Always indicates a wiring
 * bug (typo in a pipeline's `steps()` list, missing
 * {@see KernelServiceProvider} entry) — never a recoverable
 * runtime condition.
 */
final class PluginNotFoundException extends RuntimeException
{
    public static function for(string $name): self
    {
        return new self("No plugin registered under name [{$name}].");
    }
}
