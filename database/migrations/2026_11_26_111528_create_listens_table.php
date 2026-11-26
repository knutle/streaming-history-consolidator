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
        Schema::create('listens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('source', 16);
            $table->char('fingerprint', 64);
            $table->bigInteger('listened_at');
            $table->string('track_name');
            $table->string('artist_name');
            $table->string('release_name')->nullable();
            $table->unsignedBigInteger('played_ms');
            $table->uuid('recording_mbid')->nullable();
            $table->json('track_metadata');
            $table->timestamps();

            $table->unique(['user_id', 'source', 'fingerprint']);
            $table->index(['user_id', 'listened_at']);
            $table->index(['user_id', 'source']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('listens');
    }
};
