<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('database_backups', function (Blueprint $table): void {
            $table->id();
            $table->string('filename');
            $table->string('disk')->default('local');
            $table->string('path');
            $table->unsignedBigInteger('size')->nullable();
            $table->string('status')->default('pending');
            $table->string('type')->default('manual');
            $table->string('download_token', 80)->nullable()->unique();
            $table->timestamp('download_token_expires_at')->nullable();
            $table->text('error_message')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('restored_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'type']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('database_backups');
    }
};
