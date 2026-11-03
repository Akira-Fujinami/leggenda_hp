<?php

namespace App\Services\CompanyProfile;

/**
 * 依頼DK: ホスト名から、会社のドメイン(例: recruit.example.co.jp → example.co.jp、www.example.com → example.com)を出す。
 * 2段のドメインの一覧は config('company_profile.second_level_suffixes')。
 */
class CompanyDomain
{
    public function registrable(string $host): string
    {
        $host = strtolower(trim($host, '.'));
        $labels = explode('.', $host);
        $take = 2;
        foreach ((array) config('company_profile.second_level_suffixes', []) as $suffix) {
            if (str_ends_with($host, '.'.strtolower((string) $suffix))) {
                $take = 3;
                break;
            }
        }

        return implode('.', array_slice($labels, -$take));
    }

    /**
     * @param  list<string>  $ownDomains
     */
    public function isOwn(string $url, array $ownDomains): bool
    {
        $host = parse_url($url, PHP_URL_HOST);
        if (! is_string($host) || $host === '') {
            return false;
        }

        return in_array($this->registrable($host), $ownDomains, true);
    }
}
