<?php

namespace Tests\Unit\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Services\BrandWheel\BrandWheelLeadEmailContentBuilder;
use Tests\TestCase;

class BrandWheelLeadEmailContentBuilderTest extends TestCase
{
    private BrandWheelLeadEmailContentBuilder $builder;

    protected function setUp(): void
    {
        parent::setUp();
        $this->builder = new BrandWheelLeadEmailContentBuilder;
    }

    private function makeSuccessResult(array $overrides = []): BrandWheelAnalysisResult
    {
        return new BrandWheelAnalysisResult(array_merge([
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '地域社会に貢献するという理念'],
                ]],
            ],
            'axis_state_counts' => ['read' => 1, 'partial' => 0, 'unread' => 5],
            'quality_dimension_notes' => ['consistency' => '6軸を通じて一貫したメッセージが見られます。'],
            'cautions' => ['この分析はAIによる参考情報です。'],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ], $overrides));
    }

    public function test_cannot_send_when_status_is_insufficient_input(): void
    {
        $result = new BrandWheelAnalysisResult(['status' => 'insufficient_input', 'source_pages' => []]);

        $this->assertFalse($this->builder->canSend($result));
        $this->assertSame('サイトから十分な情報が読み取れなかったため', $this->builder->blockedReason($result));
    }

    public function test_cannot_send_when_status_is_error_or_pending(): void
    {
        foreach (['error', 'pending', 'running'] as $status) {
            $result = new BrandWheelAnalysisResult(['status' => $status, 'source_pages' => []]);
            $this->assertFalse($this->builder->canSend($result), "status={$status}");
            $this->assertNotNull($this->builder->blockedReason($result), "status={$status}");
        }
    }

    public function test_cannot_send_when_recruit_page_is_unreadable(): void
    {
        $result = $this->makeSuccessResult([
            'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
        ]);

        $this->assertFalse($this->builder->canSend($result));
        $this->assertSame('採用ページの内容を取得できなかったため', $this->builder->blockedReason($result));
    }

    public function test_cannot_send_when_all_six_axes_are_unread_even_if_status_is_success(): void
    {
        // statusがsuccessでも、read+partialの合計が0(=6軸すべてunread)なら
        // 「6軸すべて読み取れませんでした」を社外へ送ることは絶対にしない
        // (2026-07-30の絶対のルール)。
        $result = $this->makeSuccessResult([
            'axis_state_counts' => ['read' => 0, 'partial' => 0, 'unread' => 6],
        ]);

        $this->assertFalse($this->builder->canSend($result));
        $this->assertSame('6軸すべてサイトから読み取れなかったため', $this->builder->blockedReason($result));
    }

    public function test_can_send_when_status_is_success_and_at_least_one_axis_has_signal(): void
    {
        $result = $this->makeSuccessResult();

        $this->assertTrue($this->builder->canSend($result));
        $this->assertNull($this->builder->blockedReason($result));
    }

    public function test_can_send_when_recruit_page_is_merely_absent_not_unreadable(): void
    {
        // absent(採用ページが元々存在しない、正常系)はunreadableとは異なり
        // ブロック対象ではない。
        $result = $this->makeSuccessResult([
            'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
        ]);

        $this->assertTrue($this->builder->canSend($result));
    }

    public function test_build_throws_if_called_when_cannot_send(): void
    {
        $result = new BrandWheelAnalysisResult(['status' => 'insufficient_input', 'source_pages' => []]);

        $this->expectException(\LogicException::class);
        $this->builder->build($result, 'https://example.com');
    }

    public function test_build_never_includes_quality_dimension_notes_or_score_like_fields(): void
    {
        $result = $this->makeSuccessResult();

        $data = $this->builder->build($result, 'https://example.com');

        $this->assertArrayNotHasKey('qualityDimensionNotes', $data);
        $this->assertArrayNotHasKey('cautions', $data);
        $this->assertArrayNotHasKey('score', $data);
        $this->assertArrayNotHasKey('axisStateCounts', $data);
        $this->assertStringNotContainsString('/', $data['axisStateSummaryText']);
    }

    public function test_build_includes_only_the_first_matched_evidence_per_axis(): void
    {
        $result = $this->makeSuccessResult([
            'axes' => [
                ['axis_key' => 'will_activity', 'state' => 'read', 'matched_sub_elements' => [
                    ['key' => 'purpose', 'evidence' => '最初の抜粋'],
                    ['key' => 'business_expansion', 'evidence' => '2件目の抜粋(表示されないはず)'],
                ]],
            ],
        ]);

        $data = $this->builder->build($result, 'https://example.com');
        $willActivity = collect($data['axes'])->firstWhere('nameJa', '活動的魅力');

        $this->assertSame('最初の抜粋', $willActivity['evidence']);
        $this->assertStringNotContainsString('2件目の抜粋', json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    public function test_source_description_reflects_which_pages_were_actually_read(): void
    {
        $bothRead = $this->makeSuccessResult(['source_pages' => ['recruit_page' => 'read', 'home_page' => 'read']]);
        $onlyHome = $this->makeSuccessResult(['source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read']]);

        $this->assertSame('トップページ・採用ページの記述から読み取りました。', $this->builder->build($bothRead, 'https://example.com')['sourceDescription']);
        $this->assertSame('トップページの記述から読み取りました。', $this->builder->build($onlyHome, 'https://example.com')['sourceDescription']);
    }

    public function test_evidence_and_labels_never_contain_configured_forbidden_phrases(): void
    {
        // このクラスはフリーテキストを新規生成しないため、禁止語が混入する
        // 経路は本来無いはずだが、最後の防波堤として実際に組み立てた内容に
        // 対しても検証する。
        $result = $this->makeSuccessResult();
        $data = $this->builder->build($result, 'https://example.com');

        $serialized = json_encode($data, JSON_UNESCAPED_UNICODE);
        foreach ((array) config('brand_wheel.forbidden_phrases', []) as $phrase) {
            $this->assertStringNotContainsString($phrase, $serialized);
        }
    }
}
