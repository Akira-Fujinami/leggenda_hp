<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('analysis_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_analysis_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('job_type');
            $table->string('queue_name');
            $table->string('status')->default('pending');
            $table->unsignedTinyInteger('progress')->default(0);
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->jsonb('metadata')->nullable();
            $table->timestamps();

            $table->index(['analysis_id', 'status']);
            $table->index(['website_analysis_id', 'job_type']);
        });

        // website_analysis_id はAnalysis単位のJob(Start/Finalize)ではnullになるが、
        // PostgreSQLのUNIQUE制約はNULL同士を区別しない(重複を許してしまう)ため、
        // COALESCEで「Analysis単位」を表す番兵値(0)に変換した式インデックスで
        // 冪等性を保証する。
        DB::statement(
            'CREATE UNIQUE INDEX analysis_jobs_unique_target ON analysis_jobs '.
            '(analysis_id, COALESCE(website_analysis_id, 0), job_type)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('analysis_jobs');
    }
};
