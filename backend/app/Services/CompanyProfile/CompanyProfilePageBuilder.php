<?php

namespace App\Services\CompanyProfile;

use App\Models\Analysis;
use App\Models\WebsiteAnalysis;
use App\Support\Report\MultiSiteReportViewModel;

/**
 * 依頼DK: PPTXに入れる「私たちが理解している、{社名}という会社」のページのデータ。並びは自社 → 競合(入力順)で、
 * 「トップメッセージと制度」のページと同じ。保存済みの結果のファイルを読むだけ(AIを呼ばない・通信しない)。
 * 無効のとき・ファイルが無いとき・その会社のページが作られなかったときは、その会社の page は null。
 */
class CompanyProfilePageBuilder
{
    public function __construct(private readonly CompanyProfileStore $store) {}

    /**
     * @return list<array{website_analysis_id: int, page: ?array{company_name: string, is_self: bool, profile: array<string, mixed>}}>
     */
    public function build(Analysis $analysis, MultiSiteReportViewModel $viewModel): array
    {
        $analysis->loadMissing('websiteAnalyses.website');
        $self = $analysis->websiteAnalyses->first(fn (WebsiteAnalysis $wa) => (bool) $wa->website?->is_primary);
        $competitors = $analysis->websiteAnalyses
            ->filter(fn (WebsiteAnalysis $wa) => ! (bool) $wa->website?->is_primary)
            ->sortBy(fn (WebsiteAnalysis $wa) => $wa->website?->display_order ?? PHP_INT_MAX)
            ->values();

        $entries = [];
        if ($self !== null) {
            $entries[] = [$viewModel->selfCompanyDisplayName, $self, true];
        }
        foreach ($competitors as $index => $wa) {
            $entries[] = [(string) ($viewModel->competitors[$index]['name'] ?? $wa->website?->name ?? ''), $wa, false];
        }

        $enabled = (bool) config('company_profile.enabled');
        $rows = [];
        foreach ($entries as [$name, $wa, $isSelf]) {
            $profile = $enabled ? $this->store->profileFor((int) $analysis->id, (int) $wa->id) : null;
            $rows[] = [
                'website_analysis_id' => (int) $wa->id,
                'page' => $profile === null ? null : ['company_name' => $name, 'is_self' => $isSelf, 'profile' => $profile],
            ];
        }

        return $rows;
    }
}
