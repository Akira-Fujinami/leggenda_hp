<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('metric_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('category');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('value_type');
            $table->string('unit')->nullable();
            $table->string('source_type');
            $table->decimal('max_score', 5, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->index(['category', 'display_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('metric_definitions');
    }
};
