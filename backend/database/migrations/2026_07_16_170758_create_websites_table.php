<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('websites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->string('url');
            $table->string('normalized_url');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('display_order')->default(0);
            $table->timestamps();

            $table->unique(['project_id', 'normalized_url']);
            $table->index(['project_id', 'display_order']);
        });

        // 1プロジェクトにつき is_primary=true は最大1件であることをDBレベルでも保証する。
        DB::statement(
            'CREATE UNIQUE INDEX websites_project_id_primary_unique ON websites (project_id) WHERE is_primary = true'
        );
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('websites');
    }
};
