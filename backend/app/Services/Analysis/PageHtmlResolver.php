<?php

namespace App\Services\Analysis;

use App\Models\AnalysisPage;
use Illuminate\Support\Facades\Storage;

/**
 * AnalysisPageから、解析に使うHTMLの保存パスを解決する共通ロジック。
 * レンダリング後HTML(rendered_html_path、RenderPageJobがJS実行後のDOMを
 * 保存したもの)が利用可能ならそちらを優先し、無ければ静的HTML
 * (raw_html_path、FetchStaticPageJobが保存したもの)にフォールバックする。
 *
 * JSで本文を描画するサイトを静的HTMLだけで解析すると、本文が実質空になり
 * 誤判定する(AnalyzeHtmlSeoJob/DetectTechnologyJobで先に確立されていた
 * 方針)。3箇所目の重複実装を避けるため、2026-08-04に共通クラスへ
 * 切り出した(元々はBrandWheelAnalysisInputFactoryだけがこの優先順位に
 * 従っておらず、JS描画前提のサイトで採用ページ本文が常に空判定される
 * 不具合の原因になっていた)。
 *
 * RenderPageJobは別ジョブとして並行実行されるため、呼び出し時点で
 * まだrendered_html_pathが存在しないことは正常系として起こり得る
 * (この場合は静的HTMLへフォールバックする、既存方針のまま)。
 */
class PageHtmlResolver
{
    public const SOURCE_RENDERED = 'rendered';

    public const SOURCE_STATIC = 'static';

    /**
     * @return ?array{path: string, source: self::SOURCE_*} 読めるHTMLが無ければnull
     */
    public function resolve(?AnalysisPage $page): ?array
    {
        $disk = Storage::disk('analysis');

        if ($page?->rendered_html_path !== null && $disk->exists($page->rendered_html_path)) {
            return ['path' => $page->rendered_html_path, 'source' => self::SOURCE_RENDERED];
        }

        if ($page?->raw_html_path !== null && $disk->exists($page->raw_html_path)) {
            return ['path' => $page->raw_html_path, 'source' => self::SOURCE_STATIC];
        }

        return null;
    }
}
