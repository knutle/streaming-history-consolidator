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
        Schema::create('recording_association_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('listen_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20);
            $table->json('payload');
            $table->unsignedBigInteger('matched')->nullable();
            $table->text('error')->nullable();
            $table->timestamps();
            $table->index(['user_id', 'listen_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('recording_association_runs');
    }
};
