<?php

namespace App\Services\SiteFirstImpression;

use App\Models\WebsiteAnalysis;
use App\Services\ComparisonCommentary\ComparisonCommentaryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * 依頼CX: 自社のコーポレートTOP・採用TOPの「第一印象」を作って保存する。流れ: 材料(構造、AIを使わない) → 画面の画像を撮る
 * (失敗したら画像なしで進める) → AIを1回呼ぶ(画像つき) → 各点をサーバー側で構造と突き合わせる(SiteFirstImpressionVerifier) →
 * 通った点が、良い・悪いのどちらかでも最小件数に満たなければ、ページは作らない → ファイルに置く。
 *
 * - 結果のファイルが既にあれば、AIを呼び直さない(force=trueを除く)。PPTXを何度ダウンロードしても、AIは呼ばれない
 *   (PPTXを作る側はファイルを読むだけ)。
 * - どちらのページも読めない(材料が作れない)ときは、AIを呼ばない(skippedとして記録する)。
 * - AI呼び出しの再試行はここでは行わない(呼び出し回数を増やさないため)。
 * - ログには件数・符号だけ(ページの文字・AIの文・URLは出さない)。
 */
class SiteFirstImpressionService
{
    public function __construct(
        private readonly SiteFirstImpressionMaterialBuilder $materials,
        private readonly PageImageCapturer $capturer,
        private readonly SiteFirstImpressionVerifier $verifier,
        private readonly SiteFirstImpressionStore $store,
    ) {}

    /**
     * @return array<string, mixed> 置いた(または既にあった)結果
     */
    public function generate(WebsiteAnalysis $websiteAnalysis, bool $force = false): array
    {
        $analysisId = (int) $websiteAnalysis->analysis_id;

        if (! $force) {
            $existing = $this->store->read($analysisId);
            if ($existing !== null) {
                return $existing;
            }
        }

        $prepared = $this->prepare($websiteAnalysis);
        $result = $this->compose($prepared['material'], $prepared['images'], $prepared['image_info']);
        unset($result['candidates']);

        return $this->finish($analysisId, $result);
    }

    /**
     * 材料を作り、画面の画像を撮る(撮れなければ画像なし)。AIは呼ばない。測定コマンドからも使う。
     *
     * @return array{material: array<string, mixed>, images: list<array{label: string, mime: string, base64: string, detail: string}>, image_info: array<string, array<string, mixed>>}
     */
    public function prepare(WebsiteAnalysis $websiteAnalysis): array
    {
        $material = $this->materials->build($websiteAnalysis);
        $images = [];
        $info = [];

        if ($material['status'] === 'ok') {
            $disk = Storage::disk('analysis');
            foreach ((array) $material['pages'] as $key => $page) {
                if ((string) $page['url'] === '') {
                    $info[$key] = ['first_view' => false, 'full_page' => false, 'error' => 'no_url'];

                    continue;
                }
                $captured = $this->capturer->capture((int) $material['analysis_id'], (int) $material['website_analysis_id'], $key, (string) $page['url']);
                $info[$key] = ['first_view' => $captured['first_view'] !== null, 'full_page' => $captured['full_page'] !== null, 'error' => $captured['error']];
                foreach ([['first_view', '最初に見える範囲', 'image_detail_first_view'], ['full_page', 'ページ全体', 'image_detail_full_page']] as [$slot, $label, $detailKey]) {
                    if ($captured[$slot] !== null && $disk->exists($captured[$slot])) {
                        $images[] = ['label' => $page['label'].'の'.$label, 'mime' => 'image/jpeg', 'base64' => base64_encode((string) $disk->get($captured[$slot])), 'detail' => (string) config('site_first_impression.'.$detailKey, 'low')];
                    }
                }
            }
        }

        return ['material' => $material, 'images' => $images, 'image_info' => $info];
    }

    /**
     * 材料と画像から、AIを呼んで(材料が作れたときだけ・1回)第一印象を作り、サーバー側で確認した結果を返す(保存はしない)。
     *
     * @param  array<string, mixed>  $material
     * @param  list<array{label: string, mime: string, base64: string, detail: string}>  $images
     * @param  array<string, array<string, mixed>>  $imageInfo
     * @return array<string, mixed>
     */
    public function compose(array $material, array $images = [], array $imageInfo = []): array
    {
        $base = [
            'status' => 'done',
            'skipped' => null,
            'page' => null,
            'kept' => ['good' => 0, 'bad' => 0],
            'discarded' => [],
            'structure' => array_map(fn (array $page) => $page['structure']['counts'], (array) ($material['pages'] ?? [])),
            'images' => $imageInfo,
            'image_count' => count($images),
            'prompt_version' => (string) config('site_first_impression.prompt_version'),
            'provider' => null,
            'model' => null,
            'ai_calls' => 0,
            'usage_input_tokens' => 0,
            'usage_output_tokens' => 0,
            'generated_at' => now()->toIso8601String(),
        ];

        if ($material['status'] !== 'ok') {
            return ['skipped' => (string) $material['reason']] + $base;
        }

        try {
            $provider = app(SiteFirstImpressionProviderFactory::class)->make();
        } catch (ComparisonCommentaryException $e) {
            return ['skipped' => $e->errorCode] + $base;
        }

        $base['provider'] = $provider->name();
        $base['model'] = $provider->model();
        $base['prompt_version'] = $provider->promptVersion();

        try {
            $base['ai_calls'] = 1;
            $outcome = $provider->write($this->materials->visible($material) + ['images' => $images]);
        } catch (ComparisonCommentaryException $e) {
            return ['skipped' => $e->errorCode] + $base;
        }

        $base['usage_input_tokens'] = (int) $outcome['usage_input_tokens'];
        $base['usage_output_tokens'] = (int) $outcome['usage_output_tokens'];
        // AIの出力そのまま(確認前)は、測定のために返すだけ。ファイルにもログにも置かない。
        $base['candidates'] = $outcome['decoded'];

        $checked = $this->verifier->verifyAll($outcome['decoded'], $material);
        $base['kept'] = ['good' => count($checked['good']), 'bad' => count($checked['bad'])];
        $base['discarded'] = $checked['discarded'];

        $min = (int) config('site_first_impression.min_points_per_side', 1);
        if (count($checked['good']) < $min || count($checked['bad']) < $min) {
            return ['skipped' => 'not_enough_points'] + $base;
        }

        $base['page'] = [
            'company_name' => (string) $material['company_name'],
            'good' => $checked['good'],
            'bad' => $checked['bad'],
            'sources' => array_values(array_map(fn (array $page) => ['label' => $page['label'], 'url' => $page['url']], (array) $material['pages'])),
        ];

        return $base;
    }

    /**
     * ジョブ全体の失敗(例外・タイムアウト)を、結果のファイルとして記録する(ページは作らない)。
     * 比較の完了(SiteFirstImpressionGate)を待たせないための記録でもある。
     */
    public function recordFailure(WebsiteAnalysis $websiteAnalysis, string $reason): void
    {
        $this->store->write((int) $websiteAnalysis->analysis_id, [
            'status' => 'done',
            'skipped' => null,
            'page' => null,
            'job_failed' => $reason,
            'prompt_version' => (string) config('site_first_impression.prompt_version'),
            'ai_calls' => 0,
            'generated_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * @param  array<string, mixed>  $result
     * @return array<string, mixed>
     */
    private function finish(int $analysisId, array $result): array
    {
        $this->store->write($analysisId, $result);

        // 件数と符号だけ。ページの文字・AIの文・URL・会社名は出さない。
        Log::info('Site first impression saved', [
            'analysis_id' => $analysisId,
            'skipped' => $result['skipped'],
            'page_created' => $result['page'] !== null,
            'kept_good' => $result['kept']['good'] ?? 0,
            'kept_bad' => $result['kept']['bad'] ?? 0,
            'discarded' => $result['discarded'] ?? [],
            'structure' => $result['structure'] ?? [],
            'image_count' => $result['image_count'] ?? 0,
            'ai_calls' => $result['ai_calls'],
            'usage_input_tokens' => $result['usage_input_tokens'] ?? 0,
            'usage_output_tokens' => $result['usage_output_tokens'] ?? 0,
        ]);

        return $result;
    }
}
