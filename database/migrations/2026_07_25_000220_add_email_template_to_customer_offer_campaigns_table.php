<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customer_offer_campaigns', function (Blueprint $table): void {
            $table->string('email_template', 32)->default('classic')->after('subject');
        });
    }

    public function down(): void
    {
        Schema::table('customer_offer_campaigns', function (Blueprint $table): void {
            $table->dropColumn('email_template');
        });
    }
};
