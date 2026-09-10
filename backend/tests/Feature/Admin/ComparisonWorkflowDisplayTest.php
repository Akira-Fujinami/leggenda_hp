<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Enums\ReportFormat;
use App\Enums\ReportGenerationStatus;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼BW-1(2026-09-11、この依頼の主目的): 比較の詳細画面(/admin/analyses/
 * {id}、source_analysis_idが非null)に置いた「4段の帯」と「いまやること」
 * パネル。この管理画面を使う人間がやることは実質「3〜5社比較を作り、
 * 営業資料に差し込んだPPTXを手に入れること」であり(依頼者指定)、それが
 * 画面の主役になっていなかった(依頼者指摘)。中身(差し込みの仕組み・
 * 比較ウィザード・比較作成フォーム)は一切変えず、置き場所と見せ方だけを
 * 変える(依頼者指定の範囲)。
 */
class ComparisonWorkflowDisplayTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeComparison(array $overrides = []): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        Website::factory()->for($project)->create(['is_primary' => true]);
        Website::factory()->for($project)->create(['is_primary' => false]);

        $sourceProject = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        $source = Analysis::factory()->for($sourceProject)->create(['created_by' => $sentinel->id]);

        return Analysis::factory()->for($project)->create(array_merge([
            'created_by' => $sentinel->id,
            'source_analysis_id' => $source->id,
            'status' => AnalysisStatus::Completed,
            'progress' => 100,
        ], $overrides));
    }

    private function makeFreeDiagnosis(): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        Website::factory()->for($project)->create(['is_primary' => true]);

        return Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'status' => AnalysisStatus::Completed,
        ]);
    }

    private function attachPptx(Analysis $analysis): AnalysisAttachment
    {
        return AnalysisAttachment::factory()->create([
            'analysis_id' => $analysis->id,
            'original_filename' => '営業資料BW検証.pptx',
            'extension' => 'pptx',
        ]);
    }

    /**
     * 依頼BW-1の判定表: 資料未添付 → ②が「いま」。
     */
    public function test_stage_two_is_current_and_shows_the_upload_panel_when_no_attachment(): void
    {
        $comparison = $this->makeComparison();

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");

        $response->assertOk();
        $response->assertSee('比較する会社');
        $response->assertSee('営業資料を添付');
        $response->assertSee('診断の完了を待つ');
        $response->assertSee('資料に差し込む');
        // ②のパネル(アップロード欄)が出ている。
        $response->assertSee('営業資料(PPTX)を添付すると');
        // ④の差し込みボタンはまだ出ない。
        $response->assertDontSee('資料に差し込んでダウンロード');
    }

    /**
     * 判定表: 添付あり・診断が未完了 → ③が「いま」。
     */
    public function test_stage_three_is_current_and_shows_progress_when_diagnosis_is_not_terminal(): void
    {
        $comparison = $this->makeComparison(['status' => AnalysisStatus::Running, 'progress' => 42]);
        $this->attachPptx($comparison);

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");

        $response->assertOk();
        $response->assertSee('診断が完了しだい、資料に差し込んでダウンロードできるようになります');
        $response->assertSee('42%', false);
        $response->assertDontSee(route('admin.analyses.comparison-report.pptx-insert', $comparison->id, false));
    }

    /**
     * 判定表: 添付あり・診断完了・差し込み可能 → ④が「いま」、差し込み
     * ボタンが出る。ファイル名・差し込み位置(configの検出語)・元の資料が
     * 変わらないことを書く(依頼者指定)。
     */
    public function test_stage_four_shows_the_insert_button_when_ready(): void
    {
        $comparison = $this->makeComparison();
        $attachment = $this->attachPptx($comparison);
        Report::factory()->create([
            'analysis_id' => $comparison->id,
            'format' => ReportFormat::Pdf,
            'status' => ReportGenerationStatus::Completed,
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");

        $response->assertOk();
        $response->assertSee('資料に差し込んでダウンロード');
        $response->assertSee($attachment->original_filename);
        foreach ((array) config('admin_comparison_pptx.reference_page_keywords') as $keyword) {
            $response->assertSee($keyword);
        }
        $response->assertSee('元の資料(アップロードした営業資料そのもの)は変更されません');
        $response->assertSee(route('admin.analyses.comparison-report.pptx-insert', $comparison->id, false));
    }

    /**
     * 判定表: 添付あり・診断完了・PDFレポートが未完了/失敗 →
     * その状態を書く(空にしない、依頼者指定)。差し込みボタンは出ない。
     */
    public function test_stage_four_shows_the_report_state_instead_of_the_button_when_report_is_not_ready(): void
    {
        $cases = [
            [ReportGenerationStatus::Pending, 'レポートを生成中です'],
            [ReportGenerationStatus::Failed, 'レポートの生成に失敗したため'],
            [ReportGenerationStatus::Skipped, 'レポートの生成が見送られたため'],
        ];

        foreach ($cases as [$status, $expectedText]) {
            $comparison = $this->makeComparison();
            $this->attachPptx($comparison);
            Report::factory()->create([
                'analysis_id' => $comparison->id,
                'format' => ReportFormat::Pdf,
                'status' => $status,
            ]);

            $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");

            $response->assertOk();
            $response->assertSee($expectedText);
            $response->assertDontSee('資料に差し込んでダウンロード');
        }
    }

    /**
     * PDFレポートの行自体が無い場合(生成がまだ始まっていない等)も、
     * 空にせず「まだ準備できていません」を出す。
     */
    public function test_stage_four_shows_a_generic_message_when_no_pdf_report_row_exists_yet(): void
    {
        $comparison = $this->makeComparison();
        $this->attachPptx($comparison);
        // Reportの行を一切作らない。

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");

        $response->assertOk();
        $response->assertSee('レポートがまだ準備できていません');
        $response->assertDontSee('資料に差し込んでダウンロード');
    }

    /**
     * 差し込みの入口は、画面内に1箇所だけ(依頼者指定の禁止事項:
     * 二重に出さないこと)。「レポート」節からは外れていること。
     */
    public function test_the_insert_entry_point_appears_only_once_on_the_page(): void
    {
        $comparison = $this->makeComparison();
        $this->attachPptx($comparison);
        Report::factory()->create([
            'analysis_id' => $comparison->id,
            'format' => ReportFormat::Pdf,
            'status' => ReportGenerationStatus::Completed,
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$comparison->id}");

        $response->assertOk();
        $insertUrl = route('admin.analyses.comparison-report.pptx-insert', $comparison->id, false);
        $occurrences = substr_count($response->getContent(), $insertUrl);
        $this->assertSame(1, $occurrences, '差し込みの入口が画面内に複数出ています。');
    }

    /**
     * 依頼者指定: 無料診断(source_analysis_idがnull)では、この節自体を
     * 出さないこと。既存の見た目(既存資料カード等)が変わっていないこと。
     */
    public function test_free_diagnosis_does_not_show_the_stage_bar_and_keeps_its_existing_layout(): void
    {
        $analysis = $this->makeFreeDiagnosis();

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertDontSee('比較する会社');
        $response->assertDontSee('営業資料を添付');
        // 既存の「既存資料」カードは引き続き表示される。
        $response->assertSee('既存資料');
        $response->assertSee('アップロードされた資料はありません。');
    }

    /**
     * 無料診断のレポートダウンロードリンクが、いままでどおり動くこと
     * (依頼者指定の回帰確認)。
     */
    public function test_free_diagnosis_report_download_link_still_works(): void
    {
        $analysis = $this->makeFreeDiagnosis();
        Report::factory()->create([
            'analysis_id' => $analysis->id,
            'format' => ReportFormat::Pdf,
            'status' => ReportGenerationStatus::Completed,
            'storage_path' => 'reports/dummy.pdf',
        ]);

        $response = $this->asAdmin()->get("/admin/analyses/{$analysis->id}");

        $response->assertOk();
        $response->assertSee(route('admin.analyses.lead-report.download', [$analysis->id, 'pdf'], false));
    }
}
