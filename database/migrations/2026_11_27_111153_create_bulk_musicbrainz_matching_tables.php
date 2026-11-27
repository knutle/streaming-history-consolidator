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
        Schema::table('listens', function (Blueprint $table) {
            $table->index(['user_id', 'recording_mbid', 'source'], 'listens_user_recording_source_index');
        });
        Schema::create('bulk_musicbrainz_selections', function (Blueprint $table) {
            $table->foreignId('user_id')->primary()->constrained()->cascadeOnDelete();
            $table->char('filter_hash', 64);
            $table->boolean('all_selected')->default(false);
            $table->timestamps();
        });
        Schema::create('bulk_musicbrainz_selection_items', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained('bulk_musicbrainz_selections', 'user_id')->cascadeOnDelete();
            $table->foreignId('listen_id')->constrained()->cascadeOnDelete();
            $table->primary(['user_id', 'listen_id']);
        });
        Schema::create('bulk_musicbrainz_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('status', 20);
            $table->unsignedBigInteger('total')->default(0);
            $table->unsignedBigInteger('processed')->default(0);
            $table->unsignedBigInteger('matched')->default(0);
            $table->unsignedBigInteger('unmatched')->default(0);
            $table->unsignedBigInteger('skipped')->default(0);
            $table->unsignedBigInteger('propagated')->default(0);
            $table->text('error')->nullable();
            $table->timestamp('retry_at')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'id']);
        });
        Schema::create('bulk_musicbrainz_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('run_id')->constrained('bulk_musicbrainz_runs')->cascadeOnDelete();
            $table->foreignId('listen_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 16)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->unique(['run_id', 'listen_id']);
            $table->index(['run_id', 'status', 'id']);
        });
        Schema::create('bulk_musicbrainz_run_lookups', function (Blueprint $table) {
            $table->foreignId('run_id')->constrained('bulk_musicbrainz_runs')->cascadeOnDelete();
            $table->char('lookup_key', 64);
            $table->json('result')->nullable();
            $table->primary(['run_id', 'lookup_key']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('bulk_musicbrainz_run_lookups');
        Schema::dropIfExists('bulk_musicbrainz_run_items');
        Schema::dropIfExists('bulk_musicbrainz_runs');
        Schema::dropIfExists('bulk_musicbrainz_selection_items');
        Schema::dropIfExists('bulk_musicbrainz_selections');
        Schema::table('listens', function (Blueprint $table) {
            $table->dropIndex('listens_user_recording_source_index');
        });
    }
};
