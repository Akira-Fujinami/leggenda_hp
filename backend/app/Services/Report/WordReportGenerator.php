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
 * 2026-08-04: docs/lead-report-layout/README.mdを唯一の定義元に全面書き直し。
 * PDF版(lead-pdf.blade.php)の9ページ構成(表紙/前置き/自社分析結果/読み取れた
 * 記述/4観点/24項目の対比/触れられていなかった項目/改善提案/ご相談)と
 * 同じ構成・同じ配色・同じ文言に揃える。旧「他社ページ比較とのまとめ」は
 * 削除(所見が24項目の対比ページの言い換えになっていたため)。
 *
 * .docxはWordアプリ側のフォントで表示されるため、PDFのようなフォント埋め込みは
 * 不要 ―― 游ゴシック(Windows/Office標準の日本語フォント)を指定し、
 * 万一未インストールの環境でもWordが自動的に代替フォントへ切り替える。
 */
class WordReportGenerator
{
    private const FONT_NAME = '游ゴシック';

    /**
     * ブランド・ホイール(6軸24項目)の固定説明図。PdfReportGeneratorと同じ
     * 静的アセットを使う ―― 分析結果に依存しないため、ここでも動的生成はしない。
     * config('brand_wheel.axes.*.sub_elements')を変更したら、この画像も
     * 作り直すこと(README「リリース前チェックリスト」参照)。
     */
    private const BRAND_WHEEL_FRAMEWORK_IMAGE_PATH = 'images/brand-wheel-framework.png';

    // PDF版のgroupBandsと同じ配色(docs/lead-report-layout/README.md)。
    private const GROUP_LABELS = [
        'company_appeal' => '会社の魅力',
        'company_distance' => '会社との距離',
        'job_appeal' => '仕事の魅力',
    ];

    public function generate(ReportViewModel $viewModel): string
    {
        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT_NAME);
        $phpWord->setDefaultFontSize(11);
        $phpWord->getSettings()->setThemeFontLang(new Language(Language::JA_JP));

        $this->addCoverSection($phpWord, $viewModel);
        $this->addBrandWheelFrameworkIntroSection($phpWord);
        $this->addOverallResultsSection($phpWord, $viewModel);
        $this->addSelfAnalysisResultsSection($phpWord, $viewModel);
        $this->addBrandWheelEvidenceSection($phpWord, $viewModel);
        $this->addPerspectivesSection($phpWord, $viewModel);
        $this->addComparisonSection($phpWord, $viewModel);
        $this->addGapAnalysisSection($phpWord, $viewModel);
        $this->addImprovementProposalSection($phpWord, $viewModel);
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

    /**
     * PDF版の「採用ブランドの捉え方 ―― ブランド・ホイール」ページ(2ページ目)
     * と同内容の前置き。分析結果に依存しない固定コンテンツのため、
     * ReportViewModelを受け取らない(PDF側と同じ理由でBrandWheelHexagonRenderer
     * も通さない、2026-08-04)。
     *
     * ここに含む「読み取れなかった項目は…」の一文は、この診断で最も誤解を
     * 招きやすい箇所の断り書きのため、要約・省略せず原文のまま出すこと
     * (引用符は『』を使う ―― ユーザー指定の「絶対に消してはいけない文言」
     * 原文どおり、2026-08-04)。
     */
    private function addBrandWheelFrameworkIntroSection(PhpWord $phpWord): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('採用ブランドの捉え方 ―― ブランド・ホイール', 1);

        $section->addImage(
            resource_path(self::BRAND_WHEEL_FRAMEWORK_IMAGE_PATH),
            ['width' => 260, 'height' => 260, 'alignment' => Jc::CENTER],
        );

        $section->addTextBreak(1);
        $section->addText('採用ブランドは、大きく3つの領域に分けて捉えます。', ['size' => 10.5]);

        $table = $section->addTable(['cellMargin' => 80]);
        $groups = [
            ['label' => self::GROUP_LABELS['company_appeal'], 'desc' => 'その会社が何を目指し、どれだけの実績・規模を持っているか。活動的魅力・資産的魅力'],
            ['label' => self::GROUP_LABELS['company_distance'], 'desc' => 'どんな経営で、どんな人たちが、どんな環境で働いているか。経営スタイル・就業環境'],
            ['label' => self::GROUP_LABELS['job_appeal'], 'desc' => 'その仕事に就くと、何が得られるか。情緒的便益・金銭的便益'],
        ];

        foreach ($groups as $group) {
            $table->addRow();
            $table->addCell(2500)->addText($group['label'], ['bold' => true]);
            $table->addCell(6500)->addText($group['desc']);
        }

        $section->addTextBreak(1);
        $section->addText(
            '6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。中心のCore Value(約束する価値)は、'.
            'その24項目を貫く「この会社が候補者に約束するもの」にあたります。',
        );
        $section->addText(
            'このレポートでは、この24項目のうち何件が、サイトの記述から読み取れたかを数えています。'.
            '点数付けではなく、件数の集計です。',
        );

        $section->addTextBreak(1);
        $section->addText(
            '読み取れなかった項目は、その魅力が『無い』という意味ではありません。サイトにそう書かれていない、というだけです。'.
            'また、採用ブランドは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNSなども併せて構築するものです。'.
            '今回はそのうちサイトの記述のみを拝見しています。',
            ['size' => 9, 'color' => '666666'],
        );
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
     * PDF版3ページ目「自社ページの分析結果」と同内容。合計件数は
     * $viewModel->selfTotalMatched等(24項目の対比・改善提案ページと同じ
     * 集計値)を使う ―― セクションごとに個別集計しない
     * (docs/lead-report-layout/README.md「合計件数Nは3・6・8ページ目で
     * 必ず同じソースから算出すること」)。
     */
    private function addSelfAnalysisResultsSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('自社ページの分析結果', 1);

        if (($viewModel->brandWheelSelf['status'] ?? null) !== 'success' || ($viewModel->brandWheelSelf['axes'] ?? []) === []) {
            // 6項目すべて0件の表は「魅力のない会社」の意味になるため出さない。
            // 理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return;
        }

        $section->addText(
            '6つの項目それぞれについて、該当する内容がサイトの記述から何件読み取れたかを集計しています(点数ではありません)。'.
            '解析したURL：'.$viewModel->brandWheelSelf['analyzed_url'],
            ['size' => 9, 'italic' => true],
        );

        $section->addTextBreak(1);
        $totalsLine = "自社サイト: {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目";
        if (($viewModel->brandWheelCompetitor['status'] ?? null) === 'success' && ($viewModel->brandWheelCompetitor['axes'] ?? []) !== []) {
            $totalsLine .= "　　競合サイト: {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目";
        }
        $section->addText($totalsLine, ['bold' => true, 'size' => 14]);

        if ($viewModel->brandWheelComparison['self_points'] !== []) {
            $section->addTextBreak(1);
            $section->addText('サマリー', ['bold' => true]);
            foreach ($viewModel->brandWheelComparison['self_points'] as $point) {
                $section->addText("・{$point}");
            }
        }

        $section->addTextBreak(1);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('件数', ['bold' => true]);
        $table->addCell(5000)->addText('読み取れた内容', ['bold' => true]);

        foreach ($viewModel->brandWheelSelf['axes'] as $axis) {
            $matchedNames = array_column($axis['matched_sub_elements'], 'name');

            $table->addRow();
            $table->addCell(2500)->addText($axis['name']);
            $table->addCell(1500)->addText("{$axis['matched_count']} / {$axis['max_count']}件");
            $table->addCell(5000)->addText($matchedNames === [] ? '―' : implode('、', $matchedNames));
        }

        if ($viewModel->brandWheelSelf['key_message'] || $viewModel->brandWheelSelf['impression']) {
            $section->addTextBreak(1);

            if ($viewModel->brandWheelSelf['key_message']) {
                $section->addText('キーメッセージ：'.$viewModel->brandWheelSelf['key_message']);
            }

            if ($viewModel->brandWheelSelf['impression']) {
                $section->addText('AI解析による印象：'.$viewModel->brandWheelSelf['impression']);
            }

            // key_message/impressionがAI生成であることの開示
            // (誠実性の維持に必要、2026-08-04)。
            $section->addText('キーメッセージと印象の読み取りにはAIを使用しています。', ['size' => 8.5, 'color' => '7a7a7a']);
        }
    }

    /**
     * PDF版の「サイトから読み取れた記述」ページと同内容(2026-08-04)。
     * 該当0件の場合はこのセクション自体を出さない(見出しと空の表だけが
     * 残る状態を作らない ―― 画面側で同じ失敗を一度している)。evidenceは
     * 要約・整形・省略記号での短縮を一切しない ―― 原文との部分文字列照合を
     * 通ったものだけが残っている、というのがこのページの価値
     * (docs/lead-report-layout/README.md)。競合側のevidenceは含めない
     * (ReportViewModelBuilderが自社分のみ組み立てている、他社サイトの本文を
     * レポートに出さないため)。
     */
    private function addBrandWheelEvidenceSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        if ($viewModel->selfBrandWheelEvidenceItems === []) {
            return;
        }

        $section = $phpWord->addSection();
        $section->addTitle('サイトから読み取れた記述', 1);
        $section->addText(
            '前ページで「該当あり」とした項目について、サイトのどの記述を根拠にしたかを記載しています。'.
            '抜粋はサイト上の文章をそのまま引用したもので、要約や言い換えは含みません。',
            ['size' => 9, 'italic' => true],
        );

        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);

        $table->addRow();
        $table->addCell(2500)->addText('項目', ['bold' => true]);
        $table->addCell(2500)->addText('何について', ['bold' => true]);
        $table->addCell(4500)->addText('サイトからの記述', ['bold' => true]);

        foreach ($viewModel->selfBrandWheelEvidenceItems as $item) {
            $table->addRow();
            $table->addCell(2500)->addText($item['axis_name']);
            $table->addCell(2500)->addText($item['sub_element_name']);
            $table->addCell(4500)->addText('「'.$item['evidence'].'」');
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

    /**
     * PDF版6ページ目「24項目の対比」と同内容。●(記述あり)／－(記述が
     * 見つからなかった)で示す。○×は使わない(正解・不正解の記号であり、
     * ×が並ぶと採点で落ちたように見える。2ページ目の断り書きと矛盾する、
     * docs/lead-report-layout/README.md)。$viewModel->subElementComparison
     * (config順、24項目)が唯一の情報源。
     */
    private function addComparisonSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('24項目の対比', 1);

        if (($viewModel->brandWheelSelf['status'] ?? null) !== 'success' || ($viewModel->brandWheelSelf['axes'] ?? []) === []) {
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return;
        }

        $showCompetitorColumn = $viewModel->competitorWebsiteUrl !== null;

        $section->addText(
            '24項目それぞれについて、サイトに該当する記述があったかどうかを並べています。●は記述があったこと、'.
            '－は記述が見つからなかったことを示します。－は『その魅力が無い』という意味ではなく、そのサイトでは'.
            '触れられていなかった、という意味です。',
            ['size' => 9, 'italic' => true],
        );

        $section->addTextBreak(1);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2000)->addText('領域', ['bold' => true]);
        $table->addCell(3500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('自社', ['bold' => true]);
        if ($showCompetitorColumn) {
            $table->addCell(1500)->addText('比較', ['bold' => true]);
        }

        foreach ($viewModel->subElementComparison as $item) {
            $table->addRow();
            $table->addCell(2000)->addText(self::GROUP_LABELS[$item['group']] ?? $item['group']);
            $table->addCell(3500)->addText($item['sub_name']);
            $table->addCell(1500)->addText($item['self_matched'] ? '●' : '－');
            if ($showCompetitorColumn) {
                $table->addCell(1500)->addText($item['competitor_matched'] ? '●' : '－');
            }
        }

        $section->addTextBreak(1);
        $legend = "合計　●自社サイト {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目";
        if ($showCompetitorColumn) {
            $legend .= "　　●比較サイト {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目";
        }
        $section->addText($legend, ['size' => 9.5, 'color' => '6B6767']);
    }

    /**
     * PDF版7ページ目「サイトで触れられていなかった項目」と同内容。
     * 自社と競合のmatchedを突き合わせた3分類(A: 比較サイトにあり自社に
     * 無い/B: 自社にあり比較サイトに無い/C: どちらにも無い)。
     * $viewModel->gapAnalysisが唯一の情報源。
     */
    private function addGapAnalysisSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('サイトで触れられていなかった項目', 1);

        if (($viewModel->brandWheelSelf['status'] ?? null) !== 'success' || ($viewModel->brandWheelSelf['axes'] ?? []) === []) {
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return;
        }

        if ($viewModel->competitorWebsiteUrl === null || ($viewModel->brandWheelCompetitor['status'] ?? null) !== 'success' || ($viewModel->brandWheelCompetitor['axes'] ?? []) === []) {
            $section->addText('比較サイトが指定されていないため、この分析はご用意できませんでした。');

            return;
        }

        $gap = $viewModel->gapAnalysis;

        $section->addText(
            '書かれていないことが弱みという意味ではありません。候補者が2つのサイトを見比べたとき、'.
            'その情報を比較サイト側でしか得られない、という事実を示しています。',
            ['size' => 9, 'italic' => true],
        );

        $section->addTextBreak(1);
        $section->addText('比較サイトにあり、御社のサイトでは触れられていなかった項目('.count($gap['a']).'件)', ['bold' => true]);
        $this->addGapList($section, $gap['a']);

        $section->addTextBreak(1);
        // Bを省略しない(Aだけ並べると詰問状になる、
        // docs/lead-report-layout/README.md)。0件でも見出しと本文を出す。
        $section->addText('御社のサイトにあり、比較サイトでは触れられていなかった項目('.count($gap['b']).'件)', ['bold' => true]);
        $this->addGapList($section, $gap['b']);

        $section->addTextBreak(1);
        $section->addText('どちらのサイトでも触れられていなかった項目('.count($gap['c']).'件)', ['bold' => true]);
        if ($gap['c'] === []) {
            $section->addText('該当する項目はありませんでした');
        } else {
            $section->addText(implode('　/　', array_column($gap['c'], 'sub_name')));
        }
    }

    /**
     * @param  list<array{sub_name: string, definition: string}>  $items
     */
    private function addGapList(Section $section, array $items): void
    {
        if ($items === []) {
            $section->addText('該当する項目はありませんでした');

            return;
        }

        foreach ($items as $item) {
            $section->addText("・{$item['sub_name']}：{$item['definition']}");
        }
    }

    /**
     * PDF版8ページ目「改善提案」と同内容。ブランド・ホイール起点であること
     * (技術的な指標から作らない、docs/lead-report-layout/README.md)。
     * ワンポイントは自社のみで判定可能なため常に自社の状態から出す。
     * 領域差・3項目は$viewModel->improvementFocus(決定的な規則で選定済み)が
     * 唯一の情報源。技術的な提案(画像・速度・フォーム等)は下部に小さく残す。
     */
    private function addImprovementProposalSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();
        $section->addTitle('改善提案', 1);

        if (($viewModel->brandWheelSelf['status'] ?? null) !== 'success' || ($viewModel->brandWheelSelf['axes'] ?? []) === []) {
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return;
        }

        $onePoint = $viewModel->brandWheelComparison['one_point'];
        if ($onePoint !== null) {
            $section->addText('【ワンポイント】'.$onePoint['text'], ['bold' => true]);
            $section->addTextBreak(1);
        }

        $focus = $viewModel->improvementFocus;
        if ($focus !== null) {
            $selectedLabel = self::GROUP_LABELS[$focus['selected_group']] ?? $focus['selected_group'];
            $section->addText(
                "3つの領域のうち、候補者が比較サイト側でしか情報を得られない差が最も大きかったのは「{$selectedLabel}」でした。".
                'この領域から'.count($focus['items']).'項目を挙げます。',
            );

            $section->addTextBreak(1);
            foreach ($focus['groups'] as $group) {
                $label = self::GROUP_LABELS[$group['group']] ?? $group['group'];
                $section->addText("{$label}：自社 {$group['self_count']} / {$group['max_count']}　比較 {$group['competitor_count']} / {$group['max_count']}");
            }

            if ($focus['items'] === []) {
                $section->addTextBreak(1);
                $section->addText('該当する項目はありませんでした');
            } else {
                foreach ($focus['items'] as $i => $item) {
                    $section->addTextBreak(1);
                    $section->addText(($i + 1).'. '.$item['sub_name'], ['bold' => true]);
                    $section->addText($item['definition'], ['size' => 9, 'color' => '6B6767']);
                    $section->addText('御社のサイト：記述が見つかりませんでした');
                    $section->addText('比較サイトの記述：「'.$item['competitor_evidence'].'」');
                }

                $section->addTextBreak(1);
                $section->addText(
                    'なお、これらを『サイトに書き足す』ことで解決するとは限りません。実態はあるのに伝えられていないのか、'.
                    'まだ言葉になっていないのか ―― その切り分けについては最終ページをご覧ください。',
                );
            }
        } elseif ($onePoint !== null) {
            $section->addText('比較サイトが無いため、領域ごとの比較はご用意できません。');
        }

        if ($viewModel->topRecommendations !== []) {
            $section->addTextBreak(1);
            $section->addText('あわせて、サイトの作りについて', ['bold' => true, 'size' => 10]);
            $titles = array_map(fn (ReportRecommendationRow $r) => $r->title, $viewModel->topRecommendations);
            $section->addText(
                implode('／', $titles).'の'.count($titles).'点に改善の余地がありました。'.
                'いずれもサイトの作りに関するもので、上の『何を書くか』とは別の話です。詳細は担当者よりご説明します。',
                ['size' => 9.5, 'color' => '6B6767'],
            );
        }
    }

    /**
     * PDF版9ページ目「ここから先は、サイトの外の話です」と同内容。旧CTA
     * 「他社比較(3〜5社)」は使わない(レジェンダは採用コンサルであり、
     * サイト制作会社ではないため、docs/lead-report-layout/README.md)。
     * 3ブロック目の本文はREADMEの注記どおり相談側の推測であり、実際の
     * サービスメニューとの一致は未確認(2026-08-04、ユーザーへ確認済み:
     * 確認が取れるまでdraft.htmlの文言のまま実装する暫定対応)。
     */
    private function addCallToActionSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTextBreak(2);
        $section->addText('ここから先は、サイトの外の話です', ['bold' => true, 'size' => 18], ['alignment' => Jc::CENTER]);
        $section->addText(
            '今回お見せしたのは、御社の採用サイトに「何が書かれているか」だけです。これは採用ブランドを形づくる情報源のひとつにすぎません。',
            ['size' => 10, 'color' => '6B6767'],
            ['alignment' => Jc::CENTER],
        );
        $section->addTextBreak(1);

        $blocks = [
            ['n' => '1', 't' => '書かれていない項目には2つの意味があります', 'd' => '実態はあるのに伝えられていないのか、まだ言葉になっていないのか。前者はサイトで解決しますが、後者はサイトを直しても変わりません。'],
            ['n' => '2', 't' => 'その切り分けはサイトからはできません', 'd' => '社員が実際に何を感じているか、辞退した方が何を理由に離れたか、説明会で何を語っているか。サイト以外の情報と突き合わせて初めて判断できます。'],
            ['n' => '3', 't' => '私たちは採用ブランドの設計からご一緒します', 'd' => 'グループインタビュー、社員・内定者・辞退者へのヒアリング、説明会の設計。何を約束する会社なのかを言葉にするところから支援しています。'],
        ];

        foreach ($blocks as $block) {
            $section->addText($block['n'].'. '.$block['t'], ['bold' => true, 'size' => 12]);
            $section->addText($block['d'], ['size' => 9.5]);
            $section->addTextBreak(1);
        }

        $section->addTextBreak(1);
        $section->addText('この診断で見えた差が、伝え方の問題なのか、言語化の問題なのか。', [], ['alignment' => Jc::CENTER]);
        $section->addText('一度お話しさせてください。担当者よりご連絡いたします。', ['color' => '6B6767'], ['alignment' => Jc::CENTER]);
    }
}
