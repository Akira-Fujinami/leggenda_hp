<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('website_analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('analysis_page_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('metric_definition_id')->constrained()->cascadeOnDelete();
            $table->jsonb('raw_value')->nullable();
            $table->jsonb('normalized_value')->nullable();
            $table->decimal('score', 5, 2)->nullable();
            $table->decimal('max_score', 5, 2)->nullable();
            $table->string('status');
            $table->string('source')->nullable();
            $table->decimal('confidence', 3, 2)->nullable();
            $table->jsonb('evidence')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('measured_at')->nullable();
            $table->timestamps();

            $table->unique(['website_analysis_id', 'metric_definition_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_results');
    }
};
