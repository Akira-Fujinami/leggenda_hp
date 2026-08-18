<?php

namespace Tests\Feature\Lead;

use App\Models\LeadCompany;
use App\Models\LeadSession;
use App\Services\Lead\LeadCompanyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * App\Services\Lead\LeadCompanyResolver の企業重複判定(依頼#6・#29)。
 * 優先順位: 1.正規化ドメイン 2.企業名 3.メールドメイン 4.担当者メール。
 */
class LeadCompanyResolutionTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): LeadCompanyResolver
    {
        return app(LeadCompanyResolver::class);
    }

    public function test_same_domain_across_different_lead_sessions_resolves_to_one_company(): void
    {
        $session1 = LeadSession::factory()->create(['company_name' => '株式会社AAA', 'email' => 'yamada@aaa.co.jp']);
        $session2 = LeadSession::factory()->create(['company_name' => '株式会社AAA', 'email' => 'sato@aaa.co.jp']);
        $session3 = LeadSession::factory()->create(['company_name' => '株式会社ＡＡＡ(表記ゆれ)', 'email' => 'suzuki@aaa.co.jp']);

        $company1 = $this->resolver()->resolveForDiagnosis($session1, 'https://aaa.co.jp/careers');
        $company2 = $this->resolver()->resolveForDiagnosis($session2, 'https://aaa.co.jp/');
        $company3 = $this->resolver()->resolveForDiagnosis($session3, 'https://www.aaa.co.jp/recruit');

        $this->assertSame($company1->id, $company2->id);
        $this->assertSame($company1->id, $company3->id);
        $this->assertSame(1, LeadCompany::query()->count());
        $this->assertSame('aaa.co.jp', $company1->fresh()->normalized_domain);
    }

    public function test_different_domains_resolve_to_separate_companies(): void
    {
        $session1 = LeadSession::factory()->create(['email' => 'a@aaa.co.jp']);
        $session2 = LeadSession::factory()->create(['email' => 'b@bbb.co.jp']);

        $company1 = $this->resolver()->resolveForDiagnosis($session1, 'https://aaa.co.jp');
        $company2 = $this->resolver()->resolveForDiagnosis($session2, 'https://bbb.co.jp');

        $this->assertNotSame($company1->id, $company2->id);
        $this->assertSame(2, LeadCompany::query()->count());
    }

    public function test_falls_back_to_company_name_when_domain_is_unavailable(): void
    {
        $session1 = LeadSession::factory()->create(['company_name' => '株式会社CCC', 'email' => 'first@ccc-group.jp']);
        $session2 = LeadSession::factory()->create(['company_name' => '株式会社CCC', 'email' => 'second@ccc-group.jp']);

        // self_urlが取得できない(URL不正等)ケースを想定 ―― ドメイン抽出不可。
        $company1 = $this->resolver()->resolveForDiagnosis($session1, null);
        $company2 = $this->resolver()->resolveForDiagnosis($session2, 'not a url');

        $this->assertSame($company1->id, $company2->id);
        $this->assertSame(1, LeadCompany::query()->count());
    }

    public function test_falls_back_to_contact_email_when_name_and_domain_both_differ(): void
    {
        $session1 = LeadSession::factory()->create(['company_name' => '株式会社DDD', 'email' => 'taro@ddd.jp']);
        $session2 = LeadSession::factory()->create(['company_name' => '株式会社DDD(2回目・社名変更後)', 'email' => 'taro@ddd.jp']);

        $company1 = $this->resolver()->resolveForDiagnosis($session1, null);
        $company2 = $this->resolver()->resolveForDiagnosis($session2, null);

        $this->assertSame($company1->id, $company2->id);
        // 最新の会社名で更新される(表記ゆれの修正を反映)。
        $this->assertSame('株式会社DDD(2回目・社名変更後)', $company1->fresh()->company_name);
    }

    public function test_free_email_domains_are_not_treated_as_company_domains(): void
    {
        $session1 = LeadSession::factory()->create(['company_name' => '株式会社EEE', 'email' => 'a@gmail.com']);
        $session2 = LeadSession::factory()->create(['company_name' => '株式会社FFF', 'email' => 'b@gmail.com']);

        $company1 = $this->resolver()->resolveForDiagnosis($session1, null);
        $company2 = $this->resolver()->resolveForDiagnosis($session2, null);

        // gmail.comが企業ドメインとして扱われ誤って同一企業にならないこと。
        $this->assertNotSame($company1->id, $company2->id);
    }

    public function test_creates_a_new_company_when_nothing_matches(): void
    {
        $session = LeadSession::factory()->create(['company_name' => '株式会社GGG', 'contact_name' => '田中一郎', 'email' => 'tanaka@ggg.co.jp']);

        $company = $this->resolver()->resolveForDiagnosis($session, 'https://ggg.co.jp');

        $this->assertSame('株式会社GGG', $company->company_name);
        $this->assertSame('田中一郎', $company->primary_contact_name);
        $this->assertSame('tanaka@ggg.co.jp', $company->primary_contact_email);
        $this->assertSame('ggg.co.jp', $company->normalized_domain);
        $this->assertSame('uncontacted', $company->sales_status);
    }

    public function test_backfills_a_missing_domain_onto_a_company_first_created_without_one(): void
    {
        $session1 = LeadSession::factory()->create(['company_name' => '株式会社HHH', 'email' => 'a@hhh.co.jp']);
        $session2 = LeadSession::factory()->create(['company_name' => '株式会社HHH', 'email' => 'b@hhh.co.jp']);

        $company1 = $this->resolver()->resolveForDiagnosis($session1, null);
        $this->assertNull($company1->fresh()->normalized_domain);

        $company2 = $this->resolver()->resolveForDiagnosis($session2, 'https://hhh.co.jp');

        $this->assertSame($company1->id, $company2->id);
        $this->assertSame('hhh.co.jp', $company1->fresh()->normalized_domain);
    }
}
