<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->string('group')->nullable()->after('id');
            $table->string('type')->default('json')->after('value');
            $table->boolean('is_public')->default(true)->after('type');
        });

        DB::table('storefront_settings')->update([
            'group' => 'legacy',
            'type' => 'json',
            'is_public' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('storefront_settings', function (Blueprint $table): void {
            $table->dropColumn(['group', 'type', 'is_public']);
        });
    }
};
