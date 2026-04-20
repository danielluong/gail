<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('disk_path');
            $table->string('mime_type')->nullable();
            $table->unsignedBigInteger('size')->default(0);
            $table->string('status')->default('pending');
            $table->unsignedInteger('chunk_count')->default(0);
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });

        Schema::create('document_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->text('content');
            $table->unsignedInteger('chunk_index')->default(0);
            $table->timestamps();

            if (Schema::getConnection()->getDriverName() === 'pgsql') {
                $table->vector('embedding', 1024);
                $table->vectorIndex('embedding');
            }

            $table->index(['project_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('document_chunks');
        Schema::dropIfExists('documents');
    }
};
