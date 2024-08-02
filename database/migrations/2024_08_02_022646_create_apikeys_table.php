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
        Schema::create('apikeys', function (Blueprint $table) {
            $table->id();
            $table->json('data');
            $table->integer('used')->default(0);
            $table->foreignIdFor(\App\Models\ServiceAccount::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('apikeys');
    }
};
