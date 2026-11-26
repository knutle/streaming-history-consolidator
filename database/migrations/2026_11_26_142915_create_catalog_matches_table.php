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
        Schema::create('catalog_matches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('song_key', 64);
            $table->string('provider', 16);
            $table->json('match');
            $table->timestamps();
            $table->unique(['user_id', 'song_key', 'provider']);
        });
        Schema::table('listens', function (Blueprint $table) {
            $table->index(['user_id', 'recording_mbid']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('listens', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'recording_mbid']);
        });
        Schema::dropIfExists('catalog_matches');
    }
};
