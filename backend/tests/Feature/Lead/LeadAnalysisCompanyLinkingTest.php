<?php

namespace Tests\Feature\Lead;

use App\Jobs\Analysis\StartAnalysisJob;
use App\Models\Analysis;
use App\Models\LeadCompany;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * POST /api/lead/analyses が実際にLeadCompanyResolverを呼び出し、
 * project.lead_company_idを設定することの配線確認(依頼#5)。分析パイプライン
 * 自体はQueue::fakeで止める(既存LeadAnalysisTestと同じ方式)。
 */
class LeadAnalysisCompanyLinkingTest extends TestCase
{
    use RefreshDatabase;

    private function issueToken(string $companyName = '株式会社サンプル', string $email = 'lead@example.com'): string
    {
        $response = $this->postJson('/api/lead/onboarding', [
            'company_name' => $companyName,
            'contact_name' => '山田太郎',
            'email' => $email,
            'privacy_policy_agreed' => true,
        ]);

        return $response->json('data.token');
    }

    public function test_starting_an_analysis_links_the_project_to_a_lead_company(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        // #B-1: store()がself_urlへ1回だけ到達性チェックを行うため(SafeHttpFetcher
        // 経由)、テストではHttp::preventStrayRequests()に引っかからないよう
        // 常に200を返すfakeを敷く。
        Http::fake(['*' => Http::response('<html><body>ok</body></html>', 200)]);
        $token = $this->issueToken(email: 'yamada@example-corp.jp');

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://example-corp.jp']);

        $response->assertCreated();

        $analysis = Analysis::find($response->json('data.analysis_id'));
        $this->assertNotNull($analysis->project->lead_company_id);

        $company = LeadCompany::find($analysis->project->lead_company_id);
        $this->assertSame('example-corp.jp', $company->normalized_domain);
        $this->assertSame(1, LeadCompany::query()->count());
    }

    public function test_diagnosis_is_recorded_as_a_lead_company_regardless_of_whether_consultation_is_requested(): void
    {
        Queue::fake([StartAnalysisJob::class]);
        Http::fake(['*' => Http::response('<html><body>ok</body></html>', 200)]);
        $token = $this->issueToken(email: 'contact@no-consultation.jp');

        $response = $this->postJson("/api/lead/analyses?token={$token}", ['self_url' => 'https://no-consultation.jp']);
        $response->assertCreated();

        // 相談リクエストは一切送信していないが、企業として蓄積されていること
        // (依頼#5「相談リクエストを送信したかどうかに関係なく」)。
        $this->assertSame(1, LeadCompany::query()->count());
    }
}
