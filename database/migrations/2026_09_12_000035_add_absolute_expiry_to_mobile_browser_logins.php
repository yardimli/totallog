<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('mobile_browser_logins', function (Blueprint $table) {
            // Existing short-lived requests must restart; do not guess their timezone.
            $table->unsignedBigInteger('expires_at_epoch')->nullable()->index();
        });
    }

    public function down(): void
    {
        Schema::table('mobile_browser_logins', fn (Blueprint $table) => $table->dropColumn('expires_at_epoch'));
    }
};
