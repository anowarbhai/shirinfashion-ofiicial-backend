<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('admin_audit_logs', 'device')) {
                $table->string('device', 120)->nullable()->after('user_agent');
            }
            if (! Schema::hasColumn('admin_audit_logs', 'location')) {
                $table->string('location', 120)->nullable()->after('device');
            }
        });
    }

    public function down(): void
    {
        Schema::table('admin_audit_logs', function (Blueprint $table): void {
            $table->dropColumn(['device', 'location']);
        });
    }
};
