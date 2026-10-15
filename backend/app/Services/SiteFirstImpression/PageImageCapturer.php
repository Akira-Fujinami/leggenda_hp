<?php

namespace App\Services\SiteFirstImpression;

use App\Services\Analysis\AnalysisStoragePaths;
use App\Services\Analysis\AnalyzerClient;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CX-1: ページの画面の画像を、既存の描画の仕組み(analyzer、デスクトップ撮影)で撮る。
 *  - ページ全体(analyzerの上限は既定4,000px。config('site_first_impression.full_page_max_height')を超える分は切る)
 *  - 最初に見える範囲(幅 first_view_width × 高さ first_view_height。ページ全体の画像の左上から切り出す)
 * 描画・撮影に失敗したら、画像なしで返す(テキストの材料だけで進める)。例外は外に出さない。
 * 画像は分析の保存先(AnalysisStoragePaths::siteFirstImpressionImagePath)に置く ―― analysisDir()ごとの削除でいっしょに消える。
 * 画像はAIに渡すだけで、スライドには入れない。マイグレーションは足さない。ログには件数・サイズだけ(ページの中身は出さない)。
 */
class PageImageCapturer
{
    public function __construct(private readonly AnalysisStoragePaths $paths) {}

    /**
     * @param  'corporate'|'recruit'  $page
     * @return array{first_view: ?string, full_page: ?string, error: ?string} analysisディスク上の相対パス
     */
    public function capture(int $analysisId, int $websiteAnalysisId, string $page, string $url): array
    {
        $none = ['first_view' => null, 'full_page' => null, 'error' => null];

        if (! (bool) config('site_first_impression.images_enabled', true)) {
            return ['error' => 'images_disabled'] + $none;
        }
        if (! function_exists('imagecreatefromstring')) {
            return ['error' => 'gd_unavailable'] + $none;
        }

        try {
            $data = app(AnalyzerClient::class)->screenshot($analysisId, $websiteAnalysisId, $url, 'desktop', true);
            $disk = Storage::disk('analysis');
            $bytes = $disk->exists((string) ($data['storage_path'] ?? '')) ? (string) $disk->get((string) $data['storage_path']) : '';
            if ($bytes === '') {
                return ['error' => 'screenshot_missing'] + $none;
            }
            $source = @imagecreatefromstring($bytes);
            if ($source === false) {
                return ['error' => 'screenshot_unreadable'] + $none;
            }

            $width = imagesx($source);
            $height = imagesy($source);
            $fullHeight = min($height, max(1, (int) config('site_first_impression.full_page_max_height', 4000)));

            $fullPath = $this->paths->siteFirstImpressionImagePath($analysisId, $page.'-full.jpg');
            $disk->put($fullPath, $this->jpeg($source, $width, $fullHeight));

            $firstWidth = min($width, (int) config('site_first_impression.first_view_width', 1280));
            $firstHeight = min($height, (int) config('site_first_impression.first_view_height', 800));
            $firstPath = $this->paths->siteFirstImpressionImagePath($analysisId, $page.'-first.jpg');
            $disk->put($firstPath, $this->jpeg($source, $firstWidth, $firstHeight));
            imagedestroy($source);

            Log::info('Site first impression image captured', ['analysis_id' => $analysisId, 'page' => $page, 'width' => $width, 'height' => $height, 'document_truncated' => (bool) ($data['truncated'] ?? false)]);

            return ['first_view' => $firstPath, 'full_page' => $fullPath, 'error' => null];
        } catch (\Throwable $e) {
            Log::warning('Site first impression image capture failed', ['analysis_id' => $analysisId, 'page' => $page, 'exception' => $e::class]);

            return ['error' => $e::class] + $none;
        }
    }

    /**
     * 左上から width × height を切り出したJPEG。
     */
    private function jpeg(\GdImage $source, int $width, int $height): string
    {
        $target = imagecreatetruecolor($width, $height);
        imagecopy($target, $source, 0, 0, 0, 0, $width, $height);
        ob_start();
        imagejpeg($target, null, (int) config('site_first_impression.first_view_jpeg_quality', 80));
        $bytes = (string) ob_get_clean();
        imagedestroy($target);

        return $bytes;
    }
}
