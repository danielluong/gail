<?php

namespace App\Ai\Agents;

use App\Ai\Agents\Research\ResearchAgent;
use App\Ai\Agents\Router\RouterAgent;

enum AgentType: string
{
    case Default = 'default';
    case Research = 'research';
    case Router = 'router';
    case Limerick = 'limerick';
    case MySQLDatabase = 'mysql-database';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Default Mode',
            self::Research => 'Research Mode',
            self::Router => 'Router Mode',
            self::Limerick => 'Limerick Mode',
            self::MySQLDatabase => 'MySQL Mode',
        };
    }

    /**
     * @return class-string<BaseAgent>
     */
    public function agentClass(): string
    {
        return match ($this) {
            self::Default => ChatAgent::class,
            self::Research => ResearchAgent::class,
            self::Router => RouterAgent::class,
            self::Limerick => LimerickAgent::class,
            self::MySQLDatabase => MySQLDatabaseAgent::class,
        };
    }

    /**
     * Name of the {@see \App\Ai\Workflow\Kernel\Contracts\PipelinePlugin}
     * the kernel should dispatch for this agent type. Single-agent
     * chats fall through the default arm to `single_agent_pipeline`,
     * which uses the per-request facade from
     * {@see \App\Ai\Workflow\Kernel\KernelContext}; multi-agent
     * workflows declare an explicit pipeline plugin name here.
     *
     * Adding a new chat agent therefore needs no edit; adding a new
     * multi-agent workflow adds one line here pointing at the new
     * pipeline plugin's name.
     */
    public function pipelinePluginName(): string
    {
        return match ($this) {
            self::Research => 'research_pipeline',
            self::Router => 'router_pipeline',
            default => 'single_agent_pipeline',
        };
    }

    /**
     * @return array<int, array{key: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(
            fn (self $type) => ['key' => $type->value, 'label' => $type->label()],
            self::cases(),
        );
    }

    /**
     * Reverse lookup: which type does a given concrete BaseAgent class
     * map to? Used by the chat-UI streaming actions to stamp the
     * Kernel's routing hint without hard-coding type strings.
     *
     * @param  class-string<BaseAgent>  $class
     */
    public static function fromAgentClass(string $class): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->agentClass() === $class) {
                return $type;
            }
        }

        return null;
    }
}
