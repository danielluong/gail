<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['attachments', 'tool_calls', 'tool_results', 'usage', 'meta'] as $column) {
            DB::statement(
                "ALTER TABLE agent_conversation_messages ALTER COLUMN {$column} TYPE jsonb USING {$column}::jsonb"
            );
        }
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        foreach (['attachments', 'tool_calls', 'tool_results', 'usage', 'meta'] as $column) {
            DB::statement(
                "ALTER TABLE agent_conversation_messages ALTER COLUMN {$column} TYPE text USING {$column}::text"
            );
        }
    }
};
