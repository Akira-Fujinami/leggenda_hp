<?php

namespace App\Services\Semrush\Data;

/**
 * SeoDataProviderの正常応答。個々の指標DTOはnullable ―― プランや
 * エンドポイントの都合で一部のみ取得できた場合でも、取得できた範囲だけを
 * 埋めて返す(捏造しない)。
 */
readonly class SeoProviderResult
{
    public function __construct(
        public bool $isMock,
        public string $database,
        public string $domainScope,
        public ?SeoDomainMetrics $domain = null,
        public ?SeoKeywordMetrics $keywords = null,
        public ?SeoBacklinkMetrics $backlinks = null,
        public ?SeoCompetitorMetrics $competitors = null,
        /**
         * 保存用の生レスポンス(個人情報・APIキーを含めないこと)。
         * 通常のAPIレスポンスには含めず、ExternalDataSnapshot.raw_storage_pathに
         * 保存する用途にのみ使う。
         *
         * @var array<string, mixed>
         */
        public array $rawForStorage = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toNormalizedArray(): array
    {
        return [
            'database' => $this->database,
            'domain_scope' => $this->domainScope,
            'domain' => $this->domain?->toArray(),
            'keywords' => $this->keywords?->toArray(),
            'backlinks' => $this->backlinks?->toArray(),
            'competitors' => $this->competitors?->toArray(),
        ];
    }
}
