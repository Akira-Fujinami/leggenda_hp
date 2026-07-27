<?php

namespace Database\Factories;

use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'format' => ReportFormat::Docx,
            'storage_path' => 'reports/1/report.docx',
            'status' => ReportGenerationStatus::Pending,
            'generated_at' => null,
            'error_message' => null,
        ];
    }
}
