<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('screenshots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('device');
            $table->string('storage_path');
            $table->unsignedInteger('width');
            $table->unsignedInteger('height');
            $table->unsignedBigInteger('file_size');
            $table->string('mime_type');
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->unique(['website_analysis_id', 'device']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('screenshots');
    }
};
