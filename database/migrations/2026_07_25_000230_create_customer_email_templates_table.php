<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customer_email_templates', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('name');
            $table->string('template_key', 32)->default('custom');
            $table->string('subject');
            $table->longText('html_content');
            $table->text('text_content')->nullable();
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['template_key', 'created_at']);
        });

        Schema::table('customer_offer_campaigns', function (Blueprint $table): void {
            $table->longText('email_html')->nullable()->after('email_message');
        });
    }

    public function down(): void
    {
        Schema::table('customer_offer_campaigns', function (Blueprint $table): void {
            $table->dropColumn('email_html');
        });

        Schema::dropIfExists('customer_email_templates');
    }
};
