<?php

namespace App\Services\Semrush;

use App\Services\Semrush\Data\SeoProviderResult;

/**
 * 外部SEOデータ取得の抽象化。Semrush固有のレスポンス形式・エンドポイント名は
 * この実装の裏に隠蔽し、呼び出し側(Job/Controller/採点エンジン)は
 * SeoProviderResult/SeoProviderExceptionのみを知る。
 */
interface SeoDataProvider
{
    /**
     * @param  string  $domain  正規化済みのルートドメイン(またはサブドメイン)。URLではない。
     * @param  string  $database  Semrushのデータベース(国)コード。例: "jp", "us"。
     *
     * @throws SeoProviderException 取得に失敗した場合(認証エラー・レート制限・タイムアウト等)
     */
    public function fetch(string $domain, string $database): SeoProviderResult;

    public function name(): string;

    /**
     * このProviderが返すSeoProviderResultが常にisMock=trueかどうか。
     * キャッシュ検索(ExternalSeoDataService::findFreshCache)で、
     * provider名だけでなくis_mockも明示的に絞り込むために使う。
     */
    public function isMock(): bool;
}
