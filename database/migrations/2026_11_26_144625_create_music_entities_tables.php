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
        Schema::create('music_artists', function (Blueprint $table) {
            $table->id();
            $table->uuid('mbid')->unique();
            $table->string('name')->nullable();
            $table->string('name_source', 20)->nullable();
        });
        Schema::create('music_releases', function (Blueprint $table) {
            $table->id();
            $table->uuid('mbid')->unique();
            $table->string('name');
        });
        Schema::create('listen_artists', function (Blueprint $table) {
            $table->foreignId('listen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('music_artist_id')->constrained()->cascadeOnDelete();
            $table->primary(['listen_id', 'music_artist_id']);
            $table->index(['music_artist_id', 'listen_id']);
        });
        Schema::create('listen_releases', function (Blueprint $table) {
            $table->foreignId('listen_id')->constrained()->cascadeOnDelete();
            $table->foreignId('music_release_id')->constrained()->cascadeOnDelete();
            $table->primary(['listen_id', 'music_release_id']);
            $table->index(['music_release_id', 'listen_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listen_releases');
        Schema::dropIfExists('listen_artists');
        Schema::dropIfExists('music_releases');
        Schema::dropIfExists('music_artists');
    }
};
