<?php

namespace Tests\Unit\BrandWheel;

use App\Models\BrandWheelAnalysisResult;
use App\Models\Website;
use App\Services\BrandWheel\BrandWheelLeadResponseComposer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * リード向けAPI/Word・PDFレポート共通の唯一の定義元。2026-08-03のユーザー
 * 指摘4点を直接検証する:
 * 1. statusの判定優先順位(error→pending→recruit_page_unreadable→
 *    insufficient_input→no_matched_content→success)が固定であること
 * 2. status!=='success'のときaxesが空配列であること
 * 3. 軸の満点(max_count)がconfig('brand_wheel.axes.*.sub_elements')の
 *    件数から動的に算出されること(4→5に変えてもコード変更不要)
 * 4. evidence(原文の抜粋)がレスポンスに一切含まれないこと
 */
class BrandWheelLeadResponseComposerTest extends TestCase
{
    use RefreshDatabase;

    private function composer(): BrandWheelLeadResponseComposer
    {
        return new BrandWheelLeadResponseComposer;
    }

    private function website(): Website
    {
        return Website::factory()->create(['normalized_url' => 'https://example.com']);
    }

    public function test_null_record_resolves_to_error_with_empty_axes(): void
    {
        $result = $this->composer()->compose(null, $this->website());

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['axes']);
        $this->assertNotNull($result['status_message']);
        $this->assertNull($result['key_message']);
        $this->assertNull($result['impression']);
        $this->assertSame([], $result['impression_items']);
    }

    public function test_db_status_error_resolves_to_error_regardless_of_other_fields(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'error',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertSame('error', $result['status']);
        $this->assertSame([], $result['axes']);
    }

    public function test_db_status_pending_or_running_resolves_to_pending(): void
    {
        foreach (['pending', 'running'] as $dbStatus) {
            $record = BrandWheelAnalysisResult::factory()->create(['status' => $dbStatus]);

            $result = $this->composer()->compose($record, $this->website());

            $this->assertSame('pending', $result['status'], "db status={$dbStatus}");
            $this->assertSame([], $result['axes']);
        }
    }

    public function test_recruit_page_unreadable_takes_priority_over_insufficient_input(): void
    {
        // status='insufficient_input'でもsource_pagesは記録されるため、
        // recruit_page==='unreadable'ならinsufficient_inputより先に判定される
        // (2026-08-03のユーザー指摘: こちら側の取得失敗を優先して明示する)。
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'insufficient_input',
            'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertSame('recruit_page_unreadable', $result['status']);
    }

    public function test_recruit_page_unreadable_takes_priority_over_success_with_matched_content(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]]],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertSame('recruit_page_unreadable', $result['status']);
        $this->assertSame([], $result['axes']);
    }

    public function test_insufficient_input_without_recruit_page_unreadable(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'insufficient_input',
            'source_pages' => ['recruit_page' => 'absent', 'home_page' => 'read'],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertSame('insufficient_input', $result['status']);
    }

    public function test_success_with_all_axes_zero_matched_resolves_to_no_matched_content(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => []]],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertSame('no_matched_content', $result['status']);
        $this->assertSame([], $result['axes']);
    }

    public function test_success_with_at_least_one_matched_axis_resolves_to_success(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'テスト文言']]]],
            'key_message' => 'メッセージ',
            // 2026-08-08: impressionはstringからlist<string>へ変更。
            'impression' => ['印象その1', '印象その2'],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertSame('success', $result['status']);
        $this->assertNull($result['status_message']);
        $this->assertNotEmpty($result['axes']);
        $this->assertSame('メッセージ', $result['key_message']);
        // 'impression'は画面(frontend)向けに従来通り読点連結の文字列のまま、
        // 'impression_items'はレポート向けに配列そのものを新たに公開する
        // (2026-08-08)。
        $this->assertSame('印象その1、印象その2', $result['impression']);
        $this->assertSame(['印象その1', '印象その2'], $result['impression_items']);
    }

    public function test_impression_items_is_empty_when_the_record_has_no_impression(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'テスト文言']]]],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $this->assertNull($result['impression']);
        $this->assertSame([], $result['impression_items']);
    }

    /**
     * 2026-08-08: axes各要素に、見出し・リンクラベルのみを根拠に破棄された
     * (discarded_sub_elements.reason==='label_only_evidence')label_only_sub_elements
     * を追加した。○△－対比表の△表示用。evidence文字列自体は
     * matched_sub_elementsと同じく含めない(key/nameのみ)。
     */
    public function test_axes_expose_label_only_sub_elements_without_their_evidence_text(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
            'axes' => [[
                'axis_key' => 'will_activity',
                'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']],
                'discarded_sub_elements' => [
                    ['key' => 'business_expansion', 'evidence' => '事業紹介', 'reason' => 'label_only_evidence'],
                    ['key' => 'project_initiative', 'evidence' => '存在しない抜粋', 'reason' => 'evidence_not_found'],
                ],
            ]],
        ]);

        $result = $this->composer()->compose($record, $this->website());
        $axis = collect($result['axes'])->firstWhere('key', 'will_activity');

        // evidence_not_foundはlabel_only_sub_elementsに含まれない。
        $this->assertCount(1, $axis['label_only_sub_elements']);
        $this->assertSame('business_expansion', $axis['label_only_sub_elements'][0]['key']);
        $this->assertArrayNotHasKey('evidence', $axis['label_only_sub_elements'][0]);

        $raw = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('事業紹介', $raw);
    }

    public function test_max_count_is_derived_from_config_sub_element_count_not_hardcoded(): void
    {
        config(['brand_wheel.axes.will_activity.sub_elements' => [
            'a' => 'A', 'b' => 'B', 'c' => 'C', 'd' => 'D', 'e' => 'E',
        ]]);

        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'a', 'evidence' => 'x']]]],
        ]);

        $result = $this->composer()->compose($record, $this->website());
        $axis = collect($result['axes'])->firstWhere('key', 'will_activity');

        $this->assertSame(5, $axis['max_count']);
        $this->assertSame(1, $axis['matched_count']);
    }

    public function test_evidence_is_never_included_in_matched_sub_elements(): void
    {
        $record = BrandWheelAnalysisResult::factory()->create([
            'status' => 'success',
            'axes' => [['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => '機密のサイト本文抜粋']]]],
        ]);

        $result = $this->composer()->compose($record, $this->website());

        $raw = json_encode($result, JSON_UNESCAPED_UNICODE);
        $this->assertStringNotContainsString('機密のサイト本文抜粋', $raw);
        $this->assertStringNotContainsString('evidence', $raw);
    }

    /**
     * 社外に出る文章のため、ブランド・ホイールと同じ否定的評価語を
     * 一切含まないこと(2026-08-03のユーザー指摘)。特にrecruit_page_
     * unreadableは、こちら側の取得失敗であり相手のサイトに問題があるかの
     * ような書き方にしないこと。
     */
    public function test_no_status_message_contains_a_forbidden_phrase(): void
    {
        $forbiddenPhrases = (array) config('brand_wheel.forbidden_phrases');
        $this->assertNotEmpty($forbiddenPhrases);

        foreach ((array) config('brand_wheel.status_messages') as $status => $message) {
            foreach ($forbiddenPhrases as $phrase) {
                $this->assertStringNotContainsString($phrase, $message, "status={$status} message contains forbidden phrase '{$phrase}'");
            }
        }
    }

    public function test_recruit_page_unreadable_message_does_not_blame_the_target_site(): void
    {
        // 「こちら側の取得失敗」であることが伝わる表現になっていること
        // (「取得できなかった」であって、サイト側の不備を示唆する語を
        // 使わない)。
        $message = (string) config('brand_wheel.status_messages.recruit_page_unreadable');

        $this->assertStringContainsString('取得できなかった', $message);
        foreach (['不備', '問題がある', 'できていない', '不十分'] as $blamingPhrase) {
            $this->assertStringNotContainsString($blamingPhrase, $message);
        }
    }
}
