<?php

namespace Tests\Unit\AiAnalysis;

use App\Services\AiAnalysis\Data\AiAnalysisResult;
use Tests\TestCase;

class AiAnalysisResultTest extends TestCase
{
    private function makeResult(float $confidence): AiAnalysisResult
    {
        return new AiAnalysisResult(
            summary: 'summary',
            strengths: [],
            weaknesses: [],
            priorityActions: [],
            competitorInsights: [],
            cautions: [],
            confidence: $confidence,
            provider: 'mock',
            model: null,
            isMock: true,
        );
    }

    public function test_accepts_confidence_at_the_lower_boundary(): void
    {
        $result = $this->makeResult(0.0);

        $this->assertSame(0.0, $result->confidence);
    }

    public function test_accepts_confidence_at_the_upper_boundary(): void
    {
        $result = $this->makeResult(1.0);

        $this->assertSame(1.0, $result->confidence);
    }

    public function test_rejects_confidence_below_zero(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeResult(-0.1);
    }

    public function test_rejects_confidence_above_one(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->makeResult(1.1);
    }

    public function test_to_array_includes_the_is_mock_flag(): void
    {
        $result = $this->makeResult(0.5);

        $this->assertArrayHasKey('is_mock', $result->toArray());
        $this->assertTrue($result->toArray()['is_mock']);
    }
}
