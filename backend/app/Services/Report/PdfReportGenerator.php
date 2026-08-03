<?php

namespace App\Services\Report;

use App\Support\Report\ReportViewModel;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * レポートPDFを生成する。文面・カテゴリ判定・スコア計算のロジックは一切
 * 持たず、既にReportViewModelBuilderが組み立てたViewModelをテンプレートへ
 * 差し込むだけに徹する(Word版と表示内容を一致させるため)。
 *
 * 日本語表示にはIPAexゴシック(fonts-ipaexfont-gothic、backend/Dockerfileで
 * apt installしたもの)を@font-face経由で埋め込む。fonts-noto-cjkは
 * TrueType Collection(.ttc)でしか配布されておらずdompdf側の対応が
 * 不安定なため、単体.ttfとして配布されるIPAexゴシックを採用している。
 */
class PdfReportGenerator
{
    private const IPAEX_GOTHIC_FONT_PATH = '/usr/share/fonts/opentype/ipaexfont-gothic/ipaexg.ttf';

    /**
     * ブランド・ホイール(6軸24項目)の固定説明図。分析結果に依存しない
     * 静的アセットのため、リクエストのたびに生成しない(BrandWheelHexagonRenderer
     * は通さない ―― 毎回rsvg-convertを走らせる意味が無い、2026-08-04)。
     * config('brand_wheel.axes.*.sub_elements')を変更したら、この画像も
     * 作り直すこと(README「リリース前チェックリスト」参照)。
     */
    private const BRAND_WHEEL_FRAMEWORK_IMAGE_PATH = 'images/brand-wheel-framework.png';

    public function generate(ReportViewModel $viewModel): string
    {
        // 2026-08-04: A4横のスライド構成に変更したため'landscape'に修正。
        // ビュー側のCSS `@page { size: A4 landscape; }` と一致させる ――
        // 食い違ったままだと、dompdf内部のページ幅の想定がずれ、行の
        // 折り返し計算に悪影響が出る(実PDF確認で見つかった、折り返し境界の
        // 文字が欠落する不具合の一因と判明)。
        return Pdf::loadView('reports.lead-pdf', [
            'viewModel' => $viewModel,
            'ipaexGothicFontPath' => 'file://'.self::IPAEX_GOTHIC_FONT_PATH,
            'brandWheelFrameworkImageBase64' => base64_encode((string) file_get_contents(resource_path(self::BRAND_WHEEL_FRAMEWORK_IMAGE_PATH))),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('enable_font_subsetting', true)
            ->output();
    }
}
