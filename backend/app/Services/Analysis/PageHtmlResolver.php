<?php

namespace App\Services\Analysis;

use App\Models\AnalysisPage;
use Illuminate\Support\Facades\Log;
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

        $renderedExists = $page?->rendered_html_path !== null && $disk->exists($page->rendered_html_path);
        if ($renderedExists) {
            return ['path' => $page->rendered_html_path, 'source' => self::SOURCE_RENDERED];
        }

        $rawExists = $page?->raw_html_path !== null && $disk->exists($page->raw_html_path);
        if ($rawExists) {
            return ['path' => $page->raw_html_path, 'source' => self::SOURCE_STATIC];
        }

        // 2026-08-19追加: analysis_id=45/website_analysis_id=93の障害調査用の
        // 一時的な診断ログ。「AnalysisPage行にパスが記録されているのに、この
        // プロセスからは実ファイルが見えない」ケース(=呼び出し元がunreadable
        // 相当として扱う経路)だけを対象にする ―― パス自体が未記録
        // (採用ページが元々無い等の正常系)まで毎回ログしてノイズにしない
        // ため。呼び出し元(BrandWheelAnalysisInputFactory/AnalyzeHtmlSeoJob/
        // DetectTechnologyJob等)を問わずこの共通クラスの時点で必ず記録する
        // ことで、書き込み時(FetchRecruitPageJob/RenderPageJob)とこの
        // 呼び出し時のhostnameが一致するかを本番ログから突き合わせられる
        // ようにする。原因確定後に削除・縮小を検討する。
        if ($page !== null && ($page->rendered_html_path !== null || $page->raw_html_path !== null)) {
            Log::warning('PageHtmlResolver: recorded HTML path(s) exist in DB but no file is readable on disk', [
                'analysis_page_id' => $page->id,
                'website_analysis_id' => $page->website_analysis_id,
                'page_type' => $page->page_type->value,
                'hostname' => gethostname(),
                'rendered_html_path' => $page->rendered_html_path,
                'raw_html_path' => $page->raw_html_path,
                'rendered_exists' => $renderedExists,
                'raw_exists' => $rawExists,
                'analysis_disk_root' => (string) config('filesystems.disks.analysis.root'),
            ]);
        }

        return null;
    }
}
