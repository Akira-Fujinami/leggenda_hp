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

    public function generate(ReportViewModel $viewModel): string
    {
        return Pdf::loadView('reports.lead-pdf', [
            'viewModel' => $viewModel,
            'ipaexGothicFontPath' => 'file://'.self::IPAEX_GOTHIC_FONT_PATH,
        ])
            ->setPaper('a4', 'portrait')
            ->setOption('enable_font_subsetting', true)
            ->output();
    }
}
