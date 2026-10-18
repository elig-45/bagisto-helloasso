<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_connections', function (Blueprint $table) {
            if (Schema::hasColumn('helloasso_connections', 'enabled')) {
                $table->dropColumn('enabled');
            }

            if (! Schema::hasColumn('helloasso_connections', 'cash_in_eligible')) {
                $table->boolean('cash_in_eligible')->nullable()->after('organization_slug');
            }

            if (! Schema::hasColumn('helloasso_connections', 'cash_in_checked_at')) {
                $table->timestamp('cash_in_checked_at')->nullable()->after('cash_in_eligible');
            }
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_connections', function (Blueprint $table) {
            if (Schema::hasColumn('helloasso_connections', 'cash_in_checked_at')) {
                $table->dropColumn('cash_in_checked_at');
            }

            if (Schema::hasColumn('helloasso_connections', 'cash_in_eligible')) {
                $table->dropColumn('cash_in_eligible');
            }

            if (! Schema::hasColumn('helloasso_connections', 'enabled')) {
                $table->boolean('enabled')->default(false);
            }
        });
    }
};
