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
        Schema::create('music_artist_aliases', function (Blueprint $table) {
            $table->foreignId('music_artist_id')->constrained('music_artists')->cascadeOnDelete();
            $table->string('name');
            $table->char('name_key', 64);
            $table->primary(['music_artist_id', 'name_key']);
            $table->index('name_key');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('music_artist_aliases');
    }
};
