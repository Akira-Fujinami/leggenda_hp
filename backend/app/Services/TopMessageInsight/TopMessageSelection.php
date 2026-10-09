<?php

namespace App\Services\TopMessageInsight;

/**
 * 依頼CQ-1/CQ-2の結果。message が null なら、その会社のページは作らない。
 */
final class TopMessageSelection
{
    /**
     * @param  list<TopMessagePage>  $programPages
     */
    public function __construct(
        public readonly ?TopMessagePage $message,
        public readonly int $messageCandidateCount,
        public readonly array $programPages,
        public readonly int $programCandidateCount,
        /** 依頼CQ追補: CQA-1の追加条件(代表者の語・インタビュー除外)をかける前の、語に当たったページ数(報告用)。 */
        public readonly int $messageKeywordMatchCount = 0,
    ) {}
}
