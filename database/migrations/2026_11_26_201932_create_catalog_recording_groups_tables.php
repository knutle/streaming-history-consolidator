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
        Schema::create('catalog_recording_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->json('recording_match')->nullable();
            $table->timestamps();
        });
        Schema::create('catalog_recording_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('group_id')->constrained('catalog_recording_groups')->cascadeOnDelete();
            $table->char('catalog_key', 64);
            $table->json('catalog');
            $table->timestamps();
            $table->unique(['user_id', 'catalog_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('catalog_recording_members');
        Schema::dropIfExists('catalog_recording_groups');
    }
};
