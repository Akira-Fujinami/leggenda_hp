<?php

namespace App\Services\CompanyProfile;

use App\Models\WebsiteAnalysis;
use App\Services\TopMessagePanel\TopMessagePanelMaterialBuilder;

/**
 * 依頼DK: AIに渡す材料(AIなし・通信しない)。会社名・サイトのURL・会社のドメイン、トップメッセージのページの文(ID付き。
 * 依頼CZのパネルの材料と同じ取り方)。メッセージのページが無くても、Web検索で会社を調べるのでAIは呼ぶ。
 */
class CompanyProfileMaterialBuilder
{
    public function __construct(
        private readonly TopMessagePanelMaterialBuilder $panelMaterials,
        private readonly CompanyDomain $domains,
    ) {}

    /**
     * @return array{company_name: string, site_url: string, own_domains: list<string>, message_title: ?string, message_url: ?string, speaker: ?string, sentences: list<array{id: string, text: string}>}
     */
    public function build(WebsiteAnalysis $websiteAnalysis, string $companyName): array
    {
        $siteUrl = (string) ($websiteAnalysis->website?->url ?? '');
        $panel = $this->panelMaterials->build($websiteAnalysis);

        $hosts = [parse_url($siteUrl, PHP_URL_HOST)];
        foreach ([$panel['message_url'] ?? null] as $url) {
            $hosts[] = is_string($url) ? parse_url($url, PHP_URL_HOST) : null;
        }
        $domains = [];
        foreach ($hosts as $host) {
            if (is_string($host) && $host !== '') {
                $domains[] = $this->domains->registrable($host);
            }
        }

        return [
            'company_name' => $companyName,
            'site_url' => $siteUrl,
            'own_domains' => array_values(array_unique(array_filter($domains))),
            'message_title' => $panel['message_title'] ?? null,
            'message_url' => $panel['message_url'] ?? null,
            'speaker' => $panel['speaker'] ?? null,
            'sentences' => array_slice((array) ($panel['sentences'] ?? []), 0, (int) config('company_profile.prompt_sentences_max')),
        ];
    }
}
