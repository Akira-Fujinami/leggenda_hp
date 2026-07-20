<?php

namespace App\Services\Semrush;

readonly class SeoNormalizedDomain
{
    public function __construct(
        public string $fullHost,
        public string $rootDomain,
        public bool $isSubdomain,
    ) {
    }

    /**
     * Semrushへ渡すドメイン単位を「どちらを使ったか」を明示するため、
     * 現時点ではドメイン単位の指標は一貫してルートドメインを使う方針とする。
     */
    public function domainForLookup(): string
    {
        return $this->rootDomain;
    }

    public function scope(): string
    {
        return 'root_domain';
    }
}
