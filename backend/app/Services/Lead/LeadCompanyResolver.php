<?php

namespace App\Services\Lead;

use App\Models\LeadCompany;
use App\Models\LeadSession;
use Illuminate\Support\Facades\DB;

/**
 * 無料診断を実行した企業を、既存のLeadCompanyへ紐付けるか新規作成する
 * (管理者ダッシュボードMVP)。lead_sessionsは同じ企業でもトークン失効後の
 * 再訪問で別行になりうるため(App\Services\Lead\LeadSessionService::
 * createOrReuse()参照)、企業としての同一性はここで別途判定する。
 *
 * 判定優先順位(依頼者指定):
 *   1. 正規化した企業ドメイン(自社サイトURLのホスト名)
 *   2. 企業名(完全一致)
 *   3. 担当者メールアドレスのドメイン(フリーメールは対象外)
 *   4. 担当者メールアドレス(完全一致)
 * どれにも一致しなければ新規LeadCompanyを作成する。
 */
class LeadCompanyResolver
{
    /**
     * 個人・フリーメールのドメインは「企業ドメイン」として信頼できない
     * (無関係な複数企業が同じドメインを持つため、誤って同一企業とみなして
     * しまう)。項番3(メールドメイン一致)の判定からは除外する。
     *
     * @var list<string>
     */
    private const FREE_EMAIL_DOMAINS = [
        'gmail.com', 'yahoo.co.jp', 'yahoo.com', 'outlook.com', 'outlook.jp',
        'hotmail.com', 'icloud.com', 'me.com', 'live.jp', 'live.com',
        'docomo.ne.jp', 'ezweb.ne.jp', 'softbank.ne.jp', 'au.com',
    ];

    /**
     * @param  string|null  $selfWebsiteUrl  自社サイトの生URL(正規化前でも可、
     *                                       ホスト抽出にはparse_urlのみ使うため)。
     */
    public function resolveForDiagnosis(LeadSession $leadSession, ?string $selfWebsiteUrl): LeadCompany
    {
        $domain = $this->extractDomain($selfWebsiteUrl);
        $emailDomain = $this->extractEmailDomain($leadSession->email);

        return DB::transaction(function () use ($leadSession, $domain, $emailDomain) {
            $company = $this->findByDomain($domain)
                ?? $this->findByCompanyName($leadSession->company_name)
                ?? $this->findByEmailDomain($emailDomain)
                ?? $this->findByContactEmail($leadSession->email);

            if ($company === null) {
                return LeadCompany::query()->create([
                    'company_name' => $leadSession->company_name,
                    'normalized_domain' => $domain,
                    'primary_contact_name' => $leadSession->contact_name,
                    'primary_contact_email' => $leadSession->email,
                    'sales_status' => 'uncontacted',
                ]);
            }

            // 既存企業に新しい診断が紐付いた際、最新の情報(会社名の表記ゆれ
            // 修正・担当者交代等)を反映する。ドメインが未設定だった行に
            // 今回判明したドメインを補完することもここで行う(項番2〜4で
            // 一致した場合、次回以降は項番1で直接一致できるようになる)。
            $company->fill([
                'company_name' => $leadSession->company_name,
                'primary_contact_name' => $leadSession->contact_name,
                'primary_contact_email' => $leadSession->email,
            ]);

            if ($company->normalized_domain === null && $domain !== null) {
                $company->normalized_domain = $domain;
            }

            if ($company->isDirty()) {
                $company->save();
            }

            return $company;
        });
    }

    public function extractDomain(?string $url): ?string
    {
        if ($url === null || trim($url) === '') {
            return null;
        }

        $value = trim($url);
        if (! preg_match('#^[a-zA-Z][a-zA-Z0-9+.\-]*://#', $value)) {
            $value = 'https://'.$value;
        }

        $host = parse_url($value, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            return null;
        }

        return $this->stripWww(strtolower($host));
    }

    private function extractEmailDomain(string $email): ?string
    {
        $atPos = strrpos($email, '@');
        if ($atPos === false) {
            return null;
        }

        $domain = strtolower(substr($email, $atPos + 1));

        if ($domain === '' || in_array($domain, self::FREE_EMAIL_DOMAINS, true)) {
            return null;
        }

        return $this->stripWww($domain);
    }

    private function stripWww(string $host): string
    {
        return str_starts_with($host, 'www.') ? substr($host, 4) : $host;
    }

    private function findByDomain(?string $domain): ?LeadCompany
    {
        if ($domain === null) {
            return null;
        }

        return LeadCompany::query()->where('normalized_domain', $domain)->lockForUpdate()->first();
    }

    private function findByCompanyName(string $companyName): ?LeadCompany
    {
        return LeadCompany::query()->where('company_name', $companyName)->lockForUpdate()->first();
    }

    private function findByEmailDomain(?string $emailDomain): ?LeadCompany
    {
        if ($emailDomain === null) {
            return null;
        }

        return LeadCompany::query()->where('normalized_domain', $emailDomain)->lockForUpdate()->first();
    }

    private function findByContactEmail(string $email): ?LeadCompany
    {
        return LeadCompany::query()->where('primary_contact_email', $email)->lockForUpdate()->first();
    }
}
