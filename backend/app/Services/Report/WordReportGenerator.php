<?php

namespace App\Services\Report;

use App\Services\Lead\LeadPerspectiveComposer;
use App\Support\Report\ReportRecommendationRow;
use App\Support\Report\ReportViewModel;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * レポートWord(.docx)を生成する。文面・カテゴリ判定・スコア計算のロジックは
 * 一切持たず、PdfReportGenerator同様、ReportViewModelBuilderが組み立てた
 * ViewModelをそのままレイアウトへ差し込むだけに徹する(PDF版と表示内容を
 * 一致させるため)。
 *
 * .docxはWordアプリ側のフォントで表示されるため、PDFのようなフォント埋め込みは
 * 不要 ―― 游ゴシック(Windows/Office標準の日本語フォント)を指定し、
 * 万一未インストールの環境でもWordが自動的に代替フォントへ切り替える。
 */
class WordReportGenerator
{
    private const FONT_NAME = '游ゴシック';

    public function generate(ReportViewModel $viewModel): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT_NAME);
        $phpWord->setDefaultFontSize(11);
        $phpWord->getSettings()->setThemeFontLang(new Language(Language::JA_JP));

        $this->addCoverSection($phpWord, $viewModel);
        $this->addOverallResultsSection($phpWord, $viewModel);
        $this->addBrandWheelSection($phpWord, $viewModel);
        $this->addPerspectivesSection($phpWord, $viewModel);
        $this->addRecommendationsSection($phpWord, $viewModel);
        $this->addCallToActionSection($phpWord, $viewModel);

        $tempPath = tempnam(sys_get_temp_dir(), 'lead-report-').'.docx';

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);

            return file_get_contents($tempPath);
        } finally {
            @unlink($tempPath);
        }
    }

    private function addCoverSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addText('Webサイト診断レポート', ['bold' => true, 'size' => 28], ['spaceAfter' => 400, 'alignment' => Jc::CENTER]);
        $section->addTextBreak(4);
        $section->addText($viewModel->companyDisplayName, ['size' => 13], ['alignment' => Jc::CENTER]);
        $section->addText('対象サイト: '.$viewModel->selfWebsiteUrl, [], ['alignment' => Jc::CENTER]);

        if ($viewModel->competitorWebsiteUrl !== null) {
            $section->addText('比較サイト: '.$viewModel->competitorWebsiteUrl, [], ['alignment' => Jc::CENTER]);
        }

        $section->addText($viewModel->generatedAtLabel, [], ['alignment' => Jc::CENTER]);

        if ($viewModel->isPartial) {
            $section->addText(
                '一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。',
                ['size' => 9, 'italic' => true],
                ['alignment' => Jc::CENTER],
            );
        }
    }

    private function addOverallResultsSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTitle('総合結果', 1);
        // 社内版(7カテゴリ100点)の点数とは別建てであることを明示する
        // (2026-07-28のユーザー指摘: 商談時に取り違えないため)。
        $section->addText('採用サイトとして重要な4観点での評価', ['size' => 9, 'italic' => true]);

        $displayScore = (int) $viewModel->selfScore['display_score'];
        $coverageRate = (float) $viewModel->selfScore['coverage_rate'];
        $confidenceRate = (float) $viewModel->selfScore['confidence_rate'];
        // LeadScoreCalculatorは4観点に表示している指標だけを対象に算出する
        // ため、満点が常に100点とは限らない ―― 固定値にしないこと。
        $maxScore = (int) round((float) $viewModel->selfScore['configured_max_score']);

        $scoreLine = "{$displayScore}点 / {$maxScore}点";

        if ($coverageRate < 70) {
            $scoreLine .= '(参考スコア)';
        }

        $section->addText($scoreLine, ['bold' => true, 'size' => 18]);
        $section->addText(sprintf('測定カバー率: %s%%　確信度: %s%%', number_format($coverageRate, 2), number_format($confidenceRate, 2)));
        $section->addTextBreak(1);
        $section->addText($viewModel->overallSummaryText);

        if ($viewModel->comparisonSentence !== null) {
            $section->addTextBreak(1);
            $section->addText($viewModel->comparisonSentence);
        }
    }

    /**
     * 採用ブランドの6軸(ブランド・ホイール)。2026-08-03、画面から診断内容を
     * 外したことでレポートが6軸の唯一の配信経路になったため追加。
     * 判定ロジックは一切持たず、BrandWheelLeadResponseComposer/
     * BrandWheelComparisonSummaryComposerが組み立てた結果をそのまま
     * レイアウトへ差し込むだけ(PDF版と表示内容を一致させるため)。
     */
    private function addBrandWheelSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('採用ブランドの6軸(ブランド・ホイール)', 1);
        $section->addText(
            'サイトの記述から、各項目に該当する内容がどれだけ読み取れたかをまとめています(人による評価ではありません)。',
            ['size' => 9, 'italic' => true],
        );

        $section->addTextBreak(1);
        $section->addText('自社サイト: '.$viewModel->selfWebsiteUrl, ['bold' => true]);
        $this->addBrandWheelSiteBody($section, $viewModel->brandWheelSelf);

        if ($viewModel->competitorWebsiteUrl !== null) {
            $section->addTextBreak(1);
            $section->addText('比較サイト: '.$viewModel->competitorWebsiteUrl, ['bold' => true]);
            $this->addBrandWheelSiteBody($section, $viewModel->brandWheelCompetitor);
        }

        $this->addBrandWheelComparison($section, $viewModel->brandWheelComparison);

        $section->addTextBreak(1);
        $section->addText(
            'ブランド・ホイールは本来、サイトだけでなくグループインタビュー・口コミ・内定者/辞退者インタビュー・説明会・SNSなども併せて構築するものです。'.
            '今回はそのうちサイトの記述のみを拝見しています。キーメッセージと印象の読み取りにはAIを使用しています。',
            ['size' => 8.5, 'color' => '666666'],
        );
    }

    /**
     * @param  ?array<string, mixed>  $brandWheel  BrandWheelLeadResponseComposer::compose()の戻り値
     */
    private function addBrandWheelSiteBody(Section $section, ?array $brandWheel): void
    {
        if (($brandWheel['status'] ?? null) !== 'success') {
            // 6項目すべて0件の表は「魅力のない会社」の意味になるため出さない。
            // 理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。
            $section->addText((string) ($brandWheel['status_message'] ?? ''));

            return;
        }

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);

        $table->addRow();
        $table->addCell(2500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('件数', ['bold' => true]);
        $table->addCell(5000)->addText('読み取れた内容', ['bold' => true]);

        foreach ($brandWheel['axes'] as $axis) {
            $matchedNames = array_column($axis['matched_sub_elements'], 'name');

            $table->addRow();
            $table->addCell(2500)->addText($axis['name']);
            $table->addCell(1500)->addText("{$axis['matched_count']} / {$axis['max_count']}件");
            $table->addCell(5000)->addText($matchedNames === [] ? '―' : implode('、', $matchedNames));
        }

        if ($brandWheel['key_message'] || $brandWheel['impression']) {
            $section->addTextBreak(1);

            if ($brandWheel['key_message']) {
                $section->addText('キーメッセージ：'.$brandWheel['key_message']);
            }

            if ($brandWheel['impression']) {
                $section->addText('AI解析による印象：'.$brandWheel['impression']);
            }
        }
    }

    /**
     * @param  array{self_points: list<string>, competitor_points: list<string>, one_point: ?array{key: string, text: string}}  $comparison
     */
    private function addBrandWheelComparison(Section $section, array $comparison): void
    {
        if ($comparison['self_points'] === [] && $comparison['competitor_points'] === [] && $comparison['one_point'] === null) {
            return;
        }

        $section->addTextBreak(1);
        $section->addText('比較まとめ', ['bold' => true]);

        if ($comparison['self_points'] !== []) {
            $section->addText('【自社ページ】', ['bold' => true]);
            foreach ($comparison['self_points'] as $point) {
                $section->addText("・{$point}");
            }
        }

        if ($comparison['competitor_points'] !== []) {
            $section->addText('【他社ページ】', ['bold' => true]);
            foreach ($comparison['competitor_points'] as $point) {
                $section->addText("・{$point}");
            }
        }

        if ($comparison['one_point'] !== null) {
            $section->addText('【ワンポイント】'.$comparison['one_point']['text']);
        }
    }

    /**
     * 採用担当向けの4観点(①書くべきこと・②メッセージ・③導線・④見やすさ)。
     * 内部の7カテゴリ(technical_seo等)は表示しない ―― 表示のグルーピングを
     * 変えるだけで、採点ロジック自体は変更していない(LeadPerspectiveComposer参照)。
     *
     * 2026-08-04: 個別指標(items[*].label、社内の指標名)は一切出さず、
     * 見出し・判定バッジ・理由1文(one_liner、ReportViewModelBuilderが
     * ReportSummaryComposer::composePerspectiveOneLiner()で機械的に付与)
     * だけに畳む(PDF版と同じ扱いに揃える)。ただし「取得できなかった項目は
     * 0点として扱わず、算出の対象から外している」旨とカバー率・確信度は
     * 誠実性の維持に必要な情報のため残す。
     */
    private function addPerspectivesSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('採用担当の視点で見た診断結果', 1);
        $section->addText('4つの観点それぞれについて、判定と、その理由を一言で記載しています。', ['size' => 9, 'italic' => true]);

        foreach ($viewModel->perspectives as $perspective) {
            $section->addTitle($perspective['heading'], 2);
            $section->addText(LeadPerspectiveComposer::statusLabel($perspective['status']), ['bold' => true]);
            $section->addText($perspective['one_liner']);
            $section->addTextBreak(1);
        }

        $coverageRate = (float) $viewModel->selfScore['coverage_rate'];
        $confidenceRate = (float) $viewModel->selfScore['confidence_rate'];
        $section->addText(
            sprintf(
                '取得できなかった項目は0点として扱わず、算出の対象から外しています(測定カバー率 %s%%／確信度 %s%%)。',
                number_format($coverageRate, 1),
                number_format($confidenceRate, 1),
            ),
            ['size' => 9, 'color' => '555555'],
        );
    }

    private function addRecommendationsSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTitle('改善提案', 1);

        if ($viewModel->topRecommendations === []) {
            $section->addText('現時点で優先度の高い改善提案はありません。');

            return;
        }

        foreach ($viewModel->topRecommendations as $recommendation) {
            $this->addRecommendation($section, $recommendation);
        }
    }

    private function addRecommendation(Section $section, ReportRecommendationRow $recommendation): void
    {
        $section->addText($recommendation->title, ['bold' => true, 'size' => 12]);
        $section->addText($recommendation->description);
        $section->addText(
            "優先度: {$recommendation->priorityLabel}　影響度: {$recommendation->impactLabel}　対応工数: {$recommendation->effortLabel}",
            ['size' => 9, 'color' => '555555'],
        );
        $section->addTextBreak(1);
    }

    private function addCallToActionSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTextBreak(6);
        $section->addText('より詳しい診断・ご相談はこちら', ['bold' => true, 'size' => 18], ['alignment' => Jc::CENTER]);
        $section->addTextBreak(1);

        $competitorClause = $viewModel->competitorWebsiteUrl !== null ? '・比較サイト1社' : '';
        $section->addText("今回は自社サイト{$competitorClause}の簡易診断結果です。", [], ['alignment' => Jc::CENTER]);
        $section->addText('他社比較(3〜5社)や、詳細な改善提案については、担当者までお気軽にご相談ください。', [], ['alignment' => Jc::CENTER]);
    }
}
