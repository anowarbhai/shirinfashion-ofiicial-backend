<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_offer_campaigns', function (Blueprint $table): void {
            $table->text('email_message')->nullable()->after('message');
            $table->text('sms_message')->nullable()->after('email_message');
        });
    }

    public function down(): void
    {
        Schema::table('customer_offer_campaigns', function (Blueprint $table): void {
            $table->dropColumn(['email_message', 'sms_message']);
        });
    }
};
