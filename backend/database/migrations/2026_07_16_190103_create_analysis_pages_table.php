<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_analysis_id')->constrained()->cascadeOnDelete();
            $table->string('url', 2048);
            $table->string('final_url', 2048)->nullable();
            $table->string('page_type');
            $table->unsignedSmallInteger('http_status')->nullable();
            $table->string('content_type')->nullable();
            $table->string('raw_html_path')->nullable();
            $table->string('rendered_html_path')->nullable();
            $table->string('title')->nullable();
            $table->text('meta_description')->nullable();
            $table->unsignedInteger('h1_count')->nullable();
            $table->unsignedInteger('word_count')->nullable();
            $table->timestamp('fetched_at')->nullable();
            $table->timestamps();

            $table->unique(['website_analysis_id', 'page_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_pages');
    }
};
