<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->string('ai_call_status')->nullable()->after('notes')->index();
            $table->json('ai_call_response')->nullable()->after('ai_call_status');
            $table->timestamp('ai_call_last_attempt_at')->nullable()->after('ai_call_response');
            $table->timestamp('ai_call_callback_at')->nullable()->after('ai_call_last_attempt_at');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table): void {
            $table->dropColumn([
                'ai_call_status',
                'ai_call_response',
                'ai_call_last_attempt_at',
                'ai_call_callback_at',
            ]);
        });
    }
};
