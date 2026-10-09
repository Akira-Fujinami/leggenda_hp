<?php

namespace App\Services\TopMessageInsight;

/**
 * 依頼CQ-3: AIの呼び出し口。テストでは固定の出力を返す偽物に差し替える。
 */
interface TopMessageInsightProvider
{
    public function name(): string;

    public function model(): ?string;

    public function promptVersion(): string;

    /**
     * @param  list<TopMessagePage>  $programPages
     * @return array{decoded: array<string, mixed>, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws TopMessageInsightException
     */
    public function analyze(TopMessagePage $messagePage, array $programPages): array;
}
