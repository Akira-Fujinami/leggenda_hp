<?php

namespace Database\Factories;

use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AnalysisAttachment>
 */
class AnalysisAttachmentFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'analysis_id' => Analysis::factory(),
            'original_filename' => '既存資料.pdf',
            'storage_path' => 'attachments/1/'.$this->faker->uuid().'.pdf',
            'extension' => 'pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 1024,
        ];
    }
}
