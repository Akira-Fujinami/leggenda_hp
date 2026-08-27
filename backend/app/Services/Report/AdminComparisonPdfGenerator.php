<?php

namespace App\Services\Report;

use App\Support\Report\MultiSiteReportViewModel;
use Barryvdh\DomPDF\Facade\Pdf;

/**
 * 依頼AC(2026-08-27): 管理者向け多社比較レポート(PDFのみ、Word版は作らない
 * ―― 依頼者指定)。既存のPdfReportGenerator(リード向け、ReportViewModel専用)は
 * 無改修のまま ―― こちらは新しいビュー(reports.admin-comparison-pdf)と
 * 新しいViewModel型(MultiSiteReportViewModel)専用の別クラスとして作る。
 * フォント・画像アセットのパスはPdfReportGeneratorと同じ定数値を使う
 * (同じサーバ環境の同じ静的アセットを指すため、値を複製することに問題は無い)。
 */
class AdminComparisonPdfGenerator
{
    private const IPAEX_GOTHIC_FONT_PATH = '/usr/share/fonts/opentype/ipaexfont-gothic/ipaexg.ttf';

    private const BRAND_WHEEL_FRAMEWORK_IMAGE_PATH = 'images/brand-wheel-framework.png';

    private const LEGGENDA_LOGO_IMAGE_PATH = 'images/leggenda-logo.png';

    public function generate(MultiSiteReportViewModel $viewModel): string
    {
        return Pdf::loadView('reports.admin-comparison-pdf', [
            'viewModel' => $viewModel,
            'ipaexGothicFontPath' => 'file://'.self::IPAEX_GOTHIC_FONT_PATH,
            'brandWheelFrameworkImageBase64' => base64_encode((string) file_get_contents(resource_path(self::BRAND_WHEEL_FRAMEWORK_IMAGE_PATH))),
            'leggendaLogoImageBase64' => base64_encode((string) file_get_contents(resource_path(self::LEGGENDA_LOGO_IMAGE_PATH))),
        ])
            ->setPaper('a4', 'landscape')
            ->setOption('enable_font_subsetting', true)
            ->output();
    }
}
