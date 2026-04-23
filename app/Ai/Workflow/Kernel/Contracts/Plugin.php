<?php

namespace App\Ai\Workflow\Kernel\Contracts;

use App\Ai\Workflow\Kernel\AgentKernel;
use App\Ai\Workflow\Kernel\KernelContext;
use App\Ai\Workflow\Kernel\PluginRegistry;

/**
 * Base contract every executable unit in the Kernel runtime implements.
 * Plugins are resolved by name through the
 * {@see PluginRegistry} and dispatched by the
 * {@see AgentKernel} — never instantiated or called directly by other
 * plugins. The narrow shape (name + execute) is what allows agents,
 * pipelines, routers, and critics to share one dispatch path.
 *
 * Standardised return shape so the Kernel can compose any plugin's
 * output into a uniform top-level result + execution trace:
 *
 *   [
 *     'result' => array<string, mixed>,   // domain output (response, research, ...)
 *     'meta'   => ['plugin' => string, 'type' => string],
 *   ]
 *
 * The `meta.type` field is one of `agent | pipeline | router | critic`,
 * matching the four sub-interfaces below.
 */
interface Plugin
{
    /**
     * Stable identifier used in the registry and in the execution trace.
     * Conventionally snake_case (e.g. `researcher_step`, `chat_pipeline`).
     */
    public function getName(): string;

    /**
     * @param  array<string, mixed>  $input
     * @return array{result: array<string, mixed>, meta: array{plugin: string, type: string}}
     */
    public function execute(array $input, KernelContext $context): array;
}
