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
        Schema::create('artist_recording_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listen_id')->constrained()->cascadeOnDelete();
            $table->char('entity_key', 64);
            $table->uuid('artist_mbid');
            $table->string('source', 10);
            $table->string('status', 20);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('matched')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'entity_key', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('artist_recording_scans');
    }
};
