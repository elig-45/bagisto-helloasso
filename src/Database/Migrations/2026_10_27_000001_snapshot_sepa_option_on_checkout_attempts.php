<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('helloasso_checkout_attempts', function (Blueprint $table) {
            $table->boolean('sepa_enabled')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('helloasso_checkout_attempts', function (Blueprint $table) {
            $table->dropColumn('sepa_enabled');
        });
    }
};
