<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('status', 20)->default('completed')->after('role');
            $table->index(['conversation_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'status']);
            $table->dropColumn('status');
        });
    }
};
