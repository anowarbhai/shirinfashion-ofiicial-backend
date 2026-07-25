<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_offer_campaigns', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('channel', 16);
            $table->string('audience', 32);
            $table->boolean('only_marketing_opt_in')->default(true);
            $table->string('subject')->nullable();
            $table->text('message');
            $table->string('status', 24)->default('queued')->index();
            $table->unsignedInteger('matched_customers')->default(0);
            $table->unsignedInteger('processed_customers')->default(0);
            $table->unsignedInteger('email_sent')->default(0);
            $table->unsignedInteger('email_failed')->default(0);
            $table->unsignedInteger('sms_sent')->default(0);
            $table->unsignedInteger('sms_failed')->default(0);
            $table->unsignedInteger('skipped')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customer_offer_campaigns');
    }
};
