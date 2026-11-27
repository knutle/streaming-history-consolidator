<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('catalog_recording_auto_exclusions', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('catalog_key', 64);
            $table->timestamps();
            $table->primary(['user_id', 'catalog_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('catalog_recording_auto_exclusions');
    }
};
