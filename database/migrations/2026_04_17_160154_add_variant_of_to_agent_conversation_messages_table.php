<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->string('variant_of', 36)->nullable()->after('role');
            $table->index('variant_of');
        });
    }

    public function down(): void
    {
        Schema::table('agent_conversation_messages', function (Blueprint $table) {
            $table->dropIndex(['variant_of']);
            $table->dropColumn('variant_of');
        });
    }
};
