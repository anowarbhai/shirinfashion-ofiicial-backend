<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_otps', function (Blueprint $table): void {
            $table->id();
            $table->string('session_token')->unique();
            $table->string('purpose', 40);
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('phone', 30);
            $table->string('code_hash');
            $table->json('meta')->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('consumed_at')->nullable();
            $table->timestamps();

            $table->index(['purpose', 'phone']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_otps');
    }
};
