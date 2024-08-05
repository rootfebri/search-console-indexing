<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('oauths', function (Blueprint $table) {
            $table->id();
            $table->string('client_id');
            $table->string('project_id');
            $table->string('client_secret');
            $table->string('refresh_token');
            $table->integer('limit')->unsigned()->default(200);
            $table->integer('refresh_time')->nullable();
            $table->foreignIdFor(\App\Models\ServiceAccount::class);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('o_auth_models');
    }
};
