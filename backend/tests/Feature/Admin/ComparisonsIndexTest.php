<?php

namespace Tests\Feature\Admin;

use App\Enums\AnalysisStatus;
use App\Models\Analysis;
use App\Models\AnalysisAttachment;
use App\Models\LeadCompany;
use App\Models\Project;
use App\Models\User;
use App\Models\Website;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * 依頼BW-2(2026-09-11、この依頼で新設): 比較レポート一覧
 * (/admin/comparisons)。source_analysis_idが非nullのAnalysisのみを
 * 新しい順に、ページングしつつN+1を出さずに表示する。
 */
class ComparisonsIndexTest extends TestCase
{
    use RefreshDatabase;

    private function asAdmin(): static
    {
        return $this->withSession(['admin_authenticated' => true]);
    }

    private function makeComparison(?\DateTimeInterface $createdAt = null, int $competitorCount = 3, bool $withPptx = false, bool $withSelfWebsite = true): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        if ($withSelfWebsite) {
            Website::factory()->for($project)->create(['is_primary' => true]);
        }
        for ($i = 0; $i < $competitorCount; $i++) {
            Website::factory()->for($project)->create(['is_primary' => false]);
        }

        $sourceProject = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);
        $source = Analysis::factory()->for($sourceProject)->create(['created_by' => $sentinel->id]);

        $comparison = Analysis::factory()->for($project)->create([
            'created_by' => $sentinel->id,
            'source_analysis_id' => $source->id,
            'status' => AnalysisStatus::Completed,
            'created_at' => $createdAt ?? now(),
        ]);

        if ($withPptx) {
            AnalysisAttachment::factory()->create(['analysis_id' => $comparison->id, 'extension' => 'pptx']);
        }

        return $comparison;
    }

    private function makeFreeDiagnosis(): Analysis
    {
        $sentinel = User::factory()->create();
        $company = LeadCompany::factory()->create();
        $project = Project::factory()->for($sentinel)->create(['lead_company_id' => $company->id]);

        return Analysis::factory()->for($project)->create(['created_by' => $sentinel->id]);
    }

    public function test_only_comparisons_are_listed(): void
    {
        $comparison = $this->makeComparison();
        $this->makeFreeDiagnosis();

        $response = $this->asAdmin()->get('/admin/comparisons');

        $response->assertOk();
        $response->assertSee("#{$comparison->id}");
        $this->assertSame(1, Analysis::query()->whereNotNull('source_analysis_id')->count());
    }

    public function test_comparisons_are_ordered_newest_first(): void
    {
        $old = $this->makeComparison(now()->subDays(3));
        $new = $this->makeComparison(now());

        $response = $this->asAdmin()->get('/admin/comparisons');

        $response->assertOk();
        $content = $response->getContent();
        $this->assertTrue(strpos($content, "#{$new->id}") < strpos($content, "#{$old->id}"), '新しい比較が先に出ていません。');
    }

    public function test_shows_competitor_count_and_pptx_presence(): void
    {
        $withPptx = $this->makeComparison(competitorCount: 4, withPptx: true);
        $withoutPptx = $this->makeComparison(competitorCount: 3, withPptx: false);

        $response = $this->asAdmin()->get('/admin/comparisons');

        $response->assertOk();
        $response->assertSeeInOrder(["#{$withPptx->id}", '4社']);
        $response->assertSeeInOrder(["#{$withoutPptx->id}", '3社']);
    }

    public function test_pagination_limits_the_number_of_rows_per_page(): void
    {
        // 依頼者側の関心はページングそのものであり、サイト数ではない
        // ため、Website(WebsiteFactoryのfaker->unique()->domainWord()が
        // 有限の語彙を使い切る)を大量に作らずに済むよう、自社・競合とも
        // 作らない。
        for ($i = 0; $i < 25; $i++) {
            $this->makeComparison(competitorCount: 0, withSelfWebsite: false);
        }

        $response = $this->asAdmin()->get('/admin/comparisons');

        $response->assertOk();
        // 依頼BW-2: 1ページ20件。
        $this->assertCount(20, $response->viewData('comparisons'));
        $this->assertTrue($response->viewData('comparisons')->hasMorePages(), '2ページ目が無いため、ページングが効いていません。');
    }

    /**
     * 依頼BW-2: N+1を出さないこと。実際に発行されるクエリ数を報告する
     * ため、上限を大きめに固定して検知する(関連の先読み漏れが起きたら
     * 一覧の件数に比例してクエリが増え、この上限を超える)。
     */
    public function test_does_not_cause_n_plus_1_queries(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->makeComparison(withPptx: true);
        }

        DB::enableQueryLog();
        $response = $this->asAdmin()->get('/admin/comparisons');
        $queryCount = count(DB::getQueryLog());
        DB::disableQueryLog();

        $response->assertOk();
        // 実測7件(件数取得1・一覧本体1・with()先読み5(project/leadCompany/
        // websites/attachments/reports))で固定 ―― 一覧件数(10件)を
        // 増やしても増えないことの確認(実測値は報告に記載)。
        $this->assertSame(7, $queryCount);
    }
}
