<?php

namespace App\Services\CompanyProfile;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼DK: 「私たちが理解している、{社名}という会社」の結果(サーバーの確認を通ったものだけ)のJSONを置く/読む。
 * 置き場所は analyses/{aid}/company_profile.json(analysisDir()ごとの削除でいっしょに消える)。マイグレーションは足さない。
 *
 * ファイルの形:
 *  status: 'done'
 *  companies: { "<website_analysis_id>": {
 *     status: 'profile' | 'skipped'
 *     reason: ?string
 *     profile: ?{headline, lead, pillars: [{title, value, achievement, recognition}], sources: [{url, title}]}
 *     rejected: {符号: 件数}, ai_calls, web_searches, usage_input_tokens, usage_output_tokens
 *  } }
 *  job_failed: ?string, prompt_version, model, generated_at
 */
class CompanyProfileStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    public function exists(int $analysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->companyProfilePath($analysisId));
    }

    /**
     * @return ?array<string, mixed>
     */
    public function read(int $analysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->companyProfilePath($analysisId);
        if (! $disk->exists($path)) {
            return null;
        }
        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) && ($decoded['status'] ?? null) === 'done' ? $decoded : null;
    }

    /**
     * 1社のページの中身(status=profile)。無いときはnull。
     *
     * @return ?array<string, mixed>
     */
    public function profileFor(int $analysisId, int $websiteAnalysisId): ?array
    {
        $company = $this->read($analysisId)['companies'][(string) $websiteAnalysisId] ?? null;

        return is_array($company) && ($company['status'] ?? null) === 'profile' && is_array($company['profile'] ?? null) ? $company['profile'] : null;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    public function write(int $analysisId, array $result): void
    {
        Storage::disk('analysis')->put(
            $this->paths->companyProfilePath($analysisId),
            (string) json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }
}
