<?php

namespace App\Services\CorporateTop;

use App\Services\Analysis\AnalysisStoragePaths;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CR-1: コーポレートTOPの取得結果(HTMLとメタ情報)を、分析の保存先(analysisディスク)の中に置く/読む。
 * マイグレーションは足さない。置き場所はAnalysisStoragePaths::corporateTopHtmlPath()/corporateTopMetaPath()で、
 * analysisDir()ごとの削除(依頼CIのデータ削除)でいっしょに消える。
 *
 * メタ情報: status('found'|'not_found')、skipped(対象外にした理由)、attempts([{candidate, outcome}])、
 * 採用したときは url(取得後の最終URL)・recruit_label・recruit_url。
 */
class CorporateTopStore
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    /**
     * @return ?array<string, mixed>
     */
    public function readMeta(int $analysisId, int $websiteAnalysisId): ?array
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->corporateTopMetaPath($analysisId, $websiteAnalysisId);
        if (! $disk->exists($path)) {
            return null;
        }

        $decoded = json_decode((string) $disk->get($path), true);

        return is_array($decoded) && in_array($decoded['status'] ?? null, ['found', 'not_found'], true) ? $decoded : null;
    }

    public function readHtml(int $analysisId, int $websiteAnalysisId): ?string
    {
        $disk = Storage::disk('analysis');
        $path = $this->paths->corporateTopHtmlPath($analysisId, $websiteAnalysisId);
        if (! $disk->exists($path)) {
            return null;
        }
        $html = $disk->get($path);

        return is_string($html) && $html !== '' ? $html : null;
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    public function write(int $analysisId, int $websiteAnalysisId, array $meta, ?string $html): void
    {
        $disk = Storage::disk('analysis');
        if ($html !== null) {
            $disk->put($this->paths->corporateTopHtmlPath($analysisId, $websiteAnalysisId), $html);
        }
        $disk->put(
            $this->paths->corporateTopMetaPath($analysisId, $websiteAnalysisId),
            (string) json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
        );
    }

    public function exists(int $analysisId, int $websiteAnalysisId): bool
    {
        return Storage::disk('analysis')->exists($this->paths->corporateTopMetaPath($analysisId, $websiteAnalysisId));
    }
}
