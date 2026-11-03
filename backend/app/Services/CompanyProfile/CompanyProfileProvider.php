<?php

namespace App\Services\CompanyProfile;

/**
 * 依頼DK: 会社の理解のページの下書きを作るAI(1社1回)。テストでは偽物に差し替える(app()->instance)。
 */
interface CompanyProfileProvider
{
    public function model(): ?string;

    /**
     * @param  array<string, mixed>  $material  CompanyProfileMaterialBuilder::build()の戻り値
     * @return array{decoded: array<string, mixed>, web_searches: int, usage_input_tokens: ?int, usage_output_tokens: ?int}
     *
     * @throws \App\Services\ComparisonCommentary\ComparisonCommentaryException
     */
    public function draft(array $material): array;
}
