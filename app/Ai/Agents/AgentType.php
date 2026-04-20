<?php

namespace App\Ai\Agents;

enum AgentType: string
{
    case Default = 'default';
    case Limerick = 'limerick';
    case MySQLDatabase = 'mysql-database';

    public function label(): string
    {
        return match ($this) {
            self::Default => 'Default Agent',
            self::Limerick => 'Limerick Agent',
            self::MySQLDatabase => 'MySQL Agent',
        };
    }

    /**
     * @return class-string<ChatAgent>
     */
    public function agentClass(): string
    {
        return match ($this) {
            self::Default => ChatAgent::class,
            self::Limerick => LimerickAgent::class,
            self::MySQLDatabase => MySQLDatabaseAgent::class,
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
}
