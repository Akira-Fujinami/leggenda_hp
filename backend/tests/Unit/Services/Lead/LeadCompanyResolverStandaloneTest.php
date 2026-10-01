<?php

namespace Tests\Unit\Services\Lead;

use App\Models\LeadCompany;
use App\Services\Lead\LeadCompanyResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 依頼CJ-2(2026-10-01): LeadCompanyResolver::resolveForStandaloneComparison()。
 * 無料診断用のresolveForDiagnosis()(App\Services\Lead\LeadCompanyResolutionTest
 * が既にカバー)とは異なり、一致した既存企業の情報を一切上書きしない。
 */
class LeadCompanyResolverStandaloneTest extends TestCase
{
    use RefreshDatabase;

    private function resolver(): LeadCompanyResolver
    {
        return app(LeadCompanyResolver::class);
    }

    public function test_it_creates_a_new_company_with_no_contact_info(): void
    {
        $company = $this->resolver()->resolveForStandaloneComparison('新規株式会社', 'https://new-company.example.com');

        $this->assertSame('新規株式会社', $company->company_name);
        $this->assertSame('new-company.example.com', $company->normalized_domain);
        $this->assertNull($company->primary_contact_name);
        $this->assertNull($company->primary_contact_email);
        $this->assertTrue($company->wasRecentlyCreated);
    }

    public function test_it_matches_by_domain_and_never_overwrites_existing_fields(): void
    {
        $existing = LeadCompany::factory()->create([
            'company_name' => '正式な社名',
            'normalized_domain' => 'match.example.com',
            'primary_contact_name' => '担当 太郎',
            'primary_contact_email' => 'taro@example.com',
        ]);

        $resolved = $this->resolver()->resolveForStandaloneComparison('入力された別の名前', 'https://match.example.com');

        $this->assertSame($existing->id, $resolved->id);
        $this->assertSame('正式な社名', $resolved->company_name);
        $this->assertSame('担当 太郎', $resolved->primary_contact_name);
        $this->assertSame('taro@example.com', $resolved->primary_contact_email);
        $this->assertSame(1, LeadCompany::query()->count());
    }

    public function test_it_matches_by_company_name_when_domain_does_not_match(): void
    {
        $existing = LeadCompany::factory()->create([
            'company_name' => '名前一致株式会社',
            'normalized_domain' => 'old-domain.example.com',
        ]);

        $resolved = $this->resolver()->resolveForStandaloneComparison('名前一致株式会社', 'https://new-domain.example.com');

        $this->assertSame($existing->id, $resolved->id);
        // 依頼CJ-2: resolveForDiagnosis()と異なり、ドメイン未設定時の
        // 補完も行わない(このメソッドは一切の更新を行わないため)。
        $this->assertSame('old-domain.example.com', $resolved->normalized_domain);
    }

    /**
     * 依頼CJ-2(依頼者指定): メールを収集しないフローのため、メールドメイン
     * 一致は一切使わない。メールドメインだけが一致していても新規作成になる。
     */
    public function test_it_does_not_match_by_email_domain(): void
    {
        LeadCompany::factory()->create([
            'company_name' => '別の会社名',
            'normalized_domain' => null,
            'primary_contact_email' => 'someone@shared-mail-domain.example.com',
        ]);

        $resolved = $this->resolver()->resolveForStandaloneComparison(
            '新しい会社',
            'https://shared-mail-domain.example.com',
        );

        // normalized_domainがnullの既存行はfindByDomain()でヒットしない
        // (正規化ドメインがそもそも設定されていないため)。company_nameも
        // 一致しないため、新規作成になる。
        $this->assertTrue($resolved->wasRecentlyCreated);
        $this->assertSame(2, LeadCompany::query()->count());
    }
}
