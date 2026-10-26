<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_definitions', function (Blueprint $table) {
            $table->unsignedInteger('repeat_interval')->default(1);
            $table->date('repeat_start_date')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('task_definitions', fn (Blueprint $table) => $table->dropColumn(['repeat_interval', 'repeat_start_date']));
    }
};
