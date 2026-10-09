<?php

namespace Tests\Feature\TopMessageInsight;

use App\Models\LeadCompany;
use App\Services\Admin\LeadCompanyDeletionService;
use App\Services\TopMessageInsight\TopMessageInsightService;
use App\Services\TopMessageInsight\TopMessageInsightStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\MakesTopMessageFixtures;
use Tests\TestCase;

/**
 * 依頼CQ-3: 結果のファイルは分析の保存先の中にあるため、依頼CIのデータ削除で
 * (マイグレーション無しで)いっしょに消える。
 */
class TopMessageInsightDataDeletionTest extends TestCase
{
    use MakesTopMessageFixtures;
    use RefreshDatabase;

    public function test_the_result_file_is_deleted_together_with_the_company_data(): void
    {
        Storage::fake('analysis');
        $wa = $this->makeComparisonWebsiteAnalysis();
        $this->seedStandardCompany($wa);
        $this->fakeTopMessageProvider($this->validAiOutput());
        app(TopMessageInsightService::class)->generate($wa);

        $store = app(TopMessageInsightStore::class);
        $this->assertTrue($store->exists($wa->analysis_id, $wa->id));
        $path = "analyses/{$wa->analysis_id}/websites/{$wa->id}/top_message_insight.json";
        Storage::disk('analysis')->assertExists($path);

        $company = LeadCompany::query()->findOrFail($wa->analysis->project->lead_company_id);
        app(LeadCompanyDeletionService::class)->destroy($company, $company->company_name);

        Storage::disk('analysis')->assertMissing($path);
        $this->assertFalse($store->exists($wa->analysis_id, $wa->id));
    }
}
