<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('indexeds', function (Blueprint $table) {
            $table->string('url')->unique()->primary()->comment('URL for indexing');
            $table->boolean('success')->default(false);
            $table->integer('requested_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('indexeds');
    }
};
