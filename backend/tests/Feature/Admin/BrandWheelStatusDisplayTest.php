<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\WebsiteAnalysisStatus;
use App\Models\Analysis;
use App\Models\BrandWheelAnalysisResult;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsiteAnalysis;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼CE-2(2026-09-28): 管理画面「Brand Wheel」表で、非successの理由が
 * 日本語で分かるようにする。BrandWheelLeadResponseComposer::resolveStatus()
 * (依頼CD-1調査で判明した、success以外の5状態を判別する既存の唯一の
 * 定義元)の判定結果を読むだけで、新しい判定ロジックはAdmin\
 * AnalysisController::show()側に一切持たない。
 */
class BrandWheelStatusDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeAnalysis(): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => AnalysisStatus::Completed,
        ]);
    }

    private function makeWebsiteAnalysis(Analysis $analysis, string $name, bool $isPrimary): WebsiteAnalysis
    {
        $website = Website::factory()->for($analysis->project)->create(['name' => $name, 'is_primary' => $isPrimary]);

        return WebsiteAnalysis::factory()->create([
            'analysis_id' => $analysis->id,
            'website_id' => $website->id,
            'status' => WebsiteAnalysisStatus::Completed,
        ]);
    }

    /**
     * @return array{0: Analysis, 1: WebsiteAnalysis}
     */
    private function withResult(bool $isPrimary, array $resultAttributes): array
    {
        $analysis = $this->makeAnalysis();
        $wa = $this->makeWebsiteAnalysis($analysis, $isPrimary ? '自社サイト' : '競合サイト', $isPrimary);
        BrandWheelAnalysisResult::factory()->create(array_merge([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $wa->id,
        ], $resultAttributes));

        return [$analysis, $wa];
    }

    public function test_insufficient_input_shows_a_japanese_label_and_reason_not_the_raw_internal_value(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'insufficient_input',
            'axes' => [],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee('insufficient_input');
        $response->assertSee(config('admin_brand_wheel_status.status_labels.insufficient_input'));
        $response->assertSee(config('brand_wheel.status_messages.insufficient_input'));
    }

    public function test_recruit_page_unreadable_shows_a_japanese_label_and_reason(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'success',
            'axes' => [],
            'source_pages' => ['recruit_page' => 'unreadable', 'home_page' => 'read'],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee('recruit_page_unreadable');
        $response->assertSee(config('admin_brand_wheel_status.status_labels.recruit_page_unreadable'));
        $response->assertSee(config('brand_wheel.status_messages.recruit_page_unreadable'));
    }

    public function test_no_matched_content_shows_a_japanese_label_and_reason(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'success',
            'axes' => [],
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee('no_matched_content');
        $response->assertSee(config('admin_brand_wheel_status.status_labels.no_matched_content'));
        $response->assertSee(config('brand_wheel.status_messages.no_matched_content'));
    }

    public function test_pending_shows_a_japanese_label_and_reason(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'running',
            'axes' => [],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('admin_brand_wheel_status.status_labels.pending'));
        $response->assertSee(config('brand_wheel.status_messages.pending'));
    }

    public function test_error_shows_a_japanese_label_and_reason(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'error',
            'axes' => [],
            'error_message' => 'provider unavailable',
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('admin_brand_wheel_status.status_labels.error'));
        $response->assertSee(config('brand_wheel.status_messages.error'));
        // 既存の「エラー」列(error_message)はそのまま残ること。
        $response->assertSee('provider unavailable');
    }

    public function test_success_shows_a_japanese_label_without_a_raw_english_value(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]],
            ],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('admin_brand_wheel_status.status_labels.success'));
    }

    /**
     * 依頼CE-2必須: 自社だけでなく、競合の非successも分かるようにすること。
     */
    public function test_competitor_non_success_status_is_also_shown_in_japanese(): void
    {
        $analysis = $this->makeAnalysis();
        $selfWa = $this->makeWebsiteAnalysis($analysis, '自社サイト', true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, '競合サイト', false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'success',
            'axes' => [
                ['axis_key' => 'will_activity', 'matched_sub_elements' => [['key' => 'purpose', 'evidence' => 'x']]],
            ],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'insufficient_input',
            'axes' => [],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('競合サイト');
        $response->assertSee(config('admin_brand_wheel_status.status_labels.insufficient_input'));
        $response->assertSee(config('brand_wheel.status_messages.insufficient_input'));
    }

    /**
     * 依頼CD-3の既存表示(赤系強調)が壊れていないこと ―― 自社の非successは
     * 引き続き強調され、競合の非successは強調されない(依頼CD-3の既存挙動)。
     */
    public function test_existing_cd3_self_highlight_still_only_applies_to_the_primary_site(): void
    {
        $analysis = $this->makeAnalysis();
        $selfWa = $this->makeWebsiteAnalysis($analysis, '自社サイト', true);
        $competitorWa = $this->makeWebsiteAnalysis($analysis, '競合サイト', false);

        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $selfWa->id,
            'status' => 'insufficient_input',
            'axes' => [],
        ]);
        BrandWheelAnalysisResult::factory()->create([
            'analysis_id' => $analysis->id,
            'website_analysis_id' => $competitorWa->id,
            'status' => 'insufficient_input',
            'axes' => [],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");
        $response->assertOk();
        $content = $response->getContent();

        $this->assertStringContainsString('background: #FDEEEC;', $content);
        // 自社行にだけ強調アイコンのtitleが付いていること(競合行には付かない)。
        $this->assertStringContainsString('自社サイトのブランド・ホイール判定が成立していません', $content);
    }

    /**
     * 依頼CE-2必須: 既存データ(axesがnull等)で画面が壊れないこと ――
     * GenerateBrandWheelAnalysisJobがinsufficient_input確定時に実際に
     * 保存する形(axes:null、空配列ではない)と一致させる。
     */
    public function test_page_does_not_break_when_axes_is_stored_as_null(): void
    {
        [$analysis] = $this->withResult(true, [
            'status' => 'insufficient_input',
            'axes' => null,
            'source_pages' => ['recruit_page' => 'read', 'home_page' => 'read'],
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(config('admin_brand_wheel_status.status_labels.insufficient_input'));
    }

    /**
     * 依頼BU〜CC(巡回の実績)の既存表示が壊れていないこと(足すだけである
     * ことの非退行確認)。
     */
    public function test_existing_crawl_diagnostics_section_is_unaffected(): void
    {
        [$analysis] = $this->withResult(true, ['status' => 'success', 'axes' => []]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee('巡回の実績');
    }
}
