<?php

namespace App\Services\Report;

use App\Support\Report\ReportViewModel;
use PhpOffice\PhpWord\Element\Section;
use PhpOffice\PhpWord\IOFactory;
use PhpOffice\PhpWord\PhpWord;
use PhpOffice\PhpWord\Settings;
use PhpOffice\PhpWord\SimpleType\Jc;
use PhpOffice\PhpWord\Style\Language;

/**
 * レポートWord(.docx)を生成する。文面・カテゴリ判定ロジックは一切持たず、
 * PdfReportGenerator同様、ReportViewModelBuilderが組み立てたViewModelを
 * そのままレイアウトへ差し込むだけに徹する(PDF版と表示内容を一致させるため)。
 *
 * 2026-08-08: PDF版(lead-pdf.blade.php)の7ページ構成(表紙/前置き/自社サイトの
 * 分析結果/競合サイトの分析結果/○△－の対比表/改善提案/最終ページ)への
 * 再編に合わせて全面書き直し。旧「総合結果」(社内向け4観点スコアの
 * Word版限定セクション、PDF版には無かった)・「サイトから読み取れた記述」・
 * 「採用担当の視点で見た診断結果」(4観点)・「サイトで触れられていなかった
 * 項目」は削除し、PDF版と1:1で一致する構成にした(ユーザー指示「Word版も
 * 同じ構成に合わせること」)。
 *
 * 依頼AY-1/AY-2/AY-3(2026-09-07): PDF版が「○△－の対比表」ページと
 * 「改善提案」ページを1ページ(「診断結果 ―― 24項目の比較と改善提案」)へ
 * 統合し、「○と判定した根拠」を末尾の付録へ移したのに合わせ、Word版も
 * 同じ構成(表紙/前置き/自社サイトの分析結果/競合サイトの分析結果/
 * 診断結果統合セクション/最終ページ/付録)へ再編した。PhpWordはセクション
 * (addSection())ごとに新しいページから始まり、セクション間の改ページを
 * 外す指定はできないため、「対比表」と「改善提案」は2つのメソッドに
 * 分かれたままでも1つのaddSection()呼び出しの中身として結合し、PDF版の
 * 「1ページへ統合」に相当する構成にした(addComparisonAndImprovementSection()
 * 参照、詳細は同メソッドのコメント)。あわせて、PDF版のみにあった
 * レーダー比較図(brandWheelRadarPngComparison)をWord版にも追加した
 * (依頼AY-4、内容の一致を優先)。
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
        // 依頼R(2026-08-26)で「○と判定した根拠」ページの引用(サイトの生の
        // 原文抜粋、&/</>を含みうる)を追加した際に判明: PhpWordは既定
        // (Settings::$outputEscapingEnabled=false、0.13.0互換のための既定値)
        // ではaddText()に渡した文字列をXMLエスケープせず生のまま書き込む
        // ―― &を含むテキストが1件でもあると、生成される.docxのdocument.xml
        // 自体が不正なXMLになり、Wordで開けなくなる(実際に確認: DOMDocument::
        // loadXML()が構文エラーで失敗する)。この不具合はこのメソッドの
        // addText()呼び出しすべて(evidence/competitor_evidence/key_message/
        // impression/会社名等、サイト由来の自由記述を渡す箇所すべて)に
        // 及んでいた、この依頼以前からの潜在バグのため、1箇所で確実に
        // 直せるこのフラグを有効化する(個々のaddText()呼び出しへ手作業で
        // エスケープ処理を加える方式は、将来のaddText()追加箇所での
        // 対応漏れを構造的に防げないため採らない)。
        Settings::setOutputEscapingEnabled(true);

        $phpWord = new PhpWord;
        $phpWord->setDefaultFontName(self::FONT_NAME);
        $phpWord->setDefaultFontSize(11);
        $phpWord->getSettings()->setThemeFontLang(new Language(Language::JA_JP));

        $this->addCoverSection($phpWord, $viewModel);
        $this->addBrandWheelFrameworkIntroSection($phpWord, $viewModel);
        $this->addBrandWheelAnalysisSection(
            $phpWord, '自社サイトの分析結果', $viewModel->brandWheelSelf,
            '自社サイト', $viewModel->selfTotalMatched, $viewModel->selfTotalMax,
            $viewModel->brandWheelComparison['self_points'],
            $viewModel->selfLowContentNotice,
        );
        if ($this->competitorReadable($viewModel)) {
            $this->addBrandWheelAnalysisSection(
                $phpWord, '競合サイトの分析結果', $viewModel->brandWheelCompetitor,
                '競合サイト', $viewModel->competitorTotalMatched, $viewModel->competitorTotalMax,
                $viewModel->brandWheelComparison['competitor_points'],
            );
        }
        // 依頼AY-1/AY-4: addComparisonAndImprovementSection()がレーダー比較図を
        // 埋め込む際に作る一時PNGファイルのパス。PhpWordのaddImage()は
        // ファイルパスを保持するだけで、実際に読み込むのはIOFactory::save()の
        // 実行時(このメソッドの下のtry節)のため、addComparisonAndImprovement
        // Section()の中でaddImage()直後に@unlink()すると「保存時にはもう
        // ファイルが無い」状態になりZipArchive::addFile()が失敗する
        // (実行確認で発見)。そのため一時ファイルの削除はsave()が完了した
        // 後、このメソッドの末尾でまとめて行う。
        $radarTempPath = $this->addComparisonAndImprovementSection($phpWord, $viewModel);
        $this->addCallToActionSection($phpWord, $viewModel);
        // 依頼AY-2(2026-09-07): PDF版と同じく、「○と判定した根拠」は末尾の
        // 付録として最後に置く(以前はCTAの直前だった)。
        $this->addEvidenceSection($phpWord, $viewModel);

        $tempPath = tempnam(sys_get_temp_dir(), 'lead-report-').'.docx';

        try {
            IOFactory::createWriter($phpWord, 'Word2007')->save($tempPath);

            return file_get_contents($tempPath);
        } finally {
            @unlink($tempPath);
            if ($radarTempPath !== null) {
                @unlink($radarTempPath);
            }
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
            $section->addText('競合サイト: '.$viewModel->competitorWebsiteUrl, [], ['alignment' => Jc::CENTER]);

            // 依頼O-2/P-3(2026-08-25): 競合サイトの分析が成立しなかった場合、
            // PDF版と同じ注記を添える(案B、依頼者確定)。$competitorReadable()の
            // 判定ロジック自体は変更しない(既存のまま)。
            if (! $this->competitorReadable($viewModel)) {
                $section->addText(
                    (string) config('brand_wheel.cover_competitor_unreadable_notice'),
                    ['size' => 9, 'italic' => true],
                    ['alignment' => Jc::CENTER],
                );
            }
        }

        $section->addText($viewModel->generatedAtLabel, [], ['alignment' => Jc::CENTER]);

        if ($viewModel->isPartial) {
            $section->addText(
                '一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。',
                ['size' => 9, 'italic' => true],
                ['alignment' => Jc::CENTER],
            );
        }

        // 依頼BB-4(2026-09-08): PDF版の表紙と同内容。'unspecified'
        // (既定・大半の診断)ではnullのため何も出ない(既存の見た目を
        // 変えない)。統合セクションには追加しない(依頼AZ改参照)。
        if ($viewModel->recruitmentTrackCoverNotice !== null) {
            $section->addText(
                $viewModel->recruitmentTrackCoverNotice,
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
     * 末尾の断り書き(config('brand_wheel.axis_unread_caveat'))は、この診断で
     * 最も誤解を招きやすい箇所の注記のため、要約・省略せず原文のまま出すこと。
     * 2026-08-04時点では「絶対に消してはいけない文言」として原文固定
     * だったが、依頼AX-2(2026-09-04)で文言そのものが差し替えられた ――
     * 固定すべきは「原文の一言一句」ではなく「PDF・多社比較PDF・Wordの
     * 3箇所で同じ文言であること」であり、configの値を直接参照することで
     * それを保証する。
     */
    private function addBrandWheelFrameworkIntroSection(PhpWord $phpWord, ReportViewModel $viewModel): void
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

        // 2026-08-17: 軸単位の説明(config('brand_wheel.axes.*.definition')、
        // 既存)を追加する(依頼者指定#6)。PDF版で表(セル内ネスト)に入れた
        // ところ実PDF確認で深刻なページ分割不具合が見つかったため、Word版も
        // 最初から表の外の通常の段落として追加する(PDF版との構造整合)。
        //
        // 依頼AX-1(2026-09-04): 6カテゴリを1段落に'　'区切りで詰め込んで
        // いたため、カテゴリ名が行頭に揃わなかった。PDF版と同じく1カテゴリ=
        // 1行(段落)にし、カテゴリ名を太字にする ―― addText()は呼び出しごとに
        // スタイルが1種類しか持てない(太字部分だけ変えられない)ため、
        // addTextRun()で「太字のカテゴリ名」+「通常の定義文」を同一段落内の
        // 別ランとして組み立てる。
        $section->addTextBreak(1);
        foreach ((array) config('brand_wheel.axes', []) as $axis) {
            $textRun = $section->addTextRun(['spaceAfter' => 40]);
            $textRun->addText($axis['name_ja'].'：', ['bold' => true, 'size' => 9, 'color' => '4A4A4A']);
            $textRun->addText($axis['definition'], ['size' => 9, 'color' => '4A4A4A']);
        }

        $section->addTextBreak(1);
        $section->addText(
            '6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。中心のCore Value(約束する価値)は、'.
            'その24項目を貫く「この会社が候補者に約束するもの」にあたります。',
        );
        // 2026-08-17: 件数集計フレーミングを弱め、レポートの目的を主文にする
        // (依頼者指定#3)。URL分析対象範囲の明記(依頼者指定#5)も追加。
        $section->addText(
            '本レポートでは、サイト上から確認できた情報をもとに、候補者に伝わる情報や印象を分析しています。',
        );
        // 依頼Q-1(2026-08-25): PDF版(lead-pdf.blade.php)と同じ出し分け
        // (config('brand_wheel.crawl_disabled_scope_notice')/
        // crawl_enabled_scope_notice、依頼者確定文言)。
        $section->addText(
            $viewModel->crawlSiteEnabled
                ? (string) config('brand_wheel.crawl_enabled_scope_notice')
                : (string) config('brand_wheel.crawl_disabled_scope_notice'),
            ['size' => 9.5, 'color' => '6B6767'],
        );

        $section->addTextBreak(1);
        $section->addText(
            (string) config('brand_wheel.axis_unread_caveat'),
            ['size' => 9, 'color' => '666666'],
        );
    }

    /**
     * PDF版3・4ページ目(自社/競合サイトの分析結果、
     * partials/lead-pdf-brand-wheel-page.blade.php)と同内容。主体
     * (自社/競合)を引数で切り替えるだけの完全に同じ形式(2026-08-08、
     * ユーザー指定)。合計件数は呼び出し側($viewModel->selfTotalMatched等、
     * 対比表・改善提案と同じ集計値)を渡す ―― セクションごとに個別集計しない。
     *
     * @param  ?array<string, mixed>  $wheel  brandWheelSelf/brandWheelCompetitor
     * @param  list<string>  $summaryPoints  BrandWheelComparisonSummaryComposer::points()の戻り値(self_points/competitor_points)
     */
    private function addBrandWheelAnalysisSection(
        PhpWord $phpWord,
        string $title,
        ?array $wheel,
        string $seriesLabel,
        int $totalMatched,
        int $totalMax,
        array $summaryPoints,
        ?string $lowContentNotice = null,
    ): void {
        $section = $phpWord->addSection();
        $section->addTitle($title, 1);

        if (($wheel['status'] ?? null) !== 'success' || ($wheel['axes'] ?? []) === []) {
            // 6項目すべて0件の表は「魅力のない会社」の意味になるため出さない。
            // 理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。
            $section->addText((string) ($wheel['status_message'] ?? ''));

            return;
        }

        $section->addText(
            '本レポートでは、サイト上から確認できた情報をもとに、候補者に伝わる情報や印象を分析しています。'.
            '解析したURL：'.$wheel['analyzed_url'],
            ['size' => 9, 'italic' => true],
        );

        $section->addTextBreak(1);
        $section->addText("{$seriesLabel}　確認できた情報：{$totalMatched} / {$totalMax}項目", ['bold' => true, 'size' => 14]);
        $section->addText('ブランドホイール24項目のうち、サイト上で情報を確認できた項目数', ['size' => 8, 'color' => '9A9A9A']);
        if ($lowContentNotice !== null) {
            // 2026-08-25追加(修正5): 自社の合計matched件数が閾値未満のときの但し書き。
            $section->addText($lowContentNotice, ['size' => 8, 'color' => '6B6767']);
        }

        if ($summaryPoints !== []) {
            $section->addTextBreak(1);
            $section->addText('サマリー', ['bold' => true]);
            foreach ($summaryPoints as $point) {
                $section->addText("・{$point}");
            }
        }

        $section->addTextBreak(1);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('件数', ['bold' => true]);
        $table->addCell(5000)->addText('読み取れた内容', ['bold' => true]);

        foreach ($wheel['axes'] as $axis) {
            $matchedNames = array_column($axis['matched_sub_elements'], 'name');

            $table->addRow();
            $table->addCell(2500)->addText($axis['name']);
            $table->addCell(1500)->addText("{$axis['matched_count']} / {$axis['max_count']}件");
            // 「該当する記述は見つかりませんでした」を使う(内容が無い会社、
            // と読める表現を避けるため)。PDF版(.none2)と表記を揃える。
            $table->addCell(5000)->addText($matchedNames === [] ? '該当する記述は見つかりませんでした' : implode('、', $matchedNames));
        }

        // 2026-08-18: 「候補者に与える印象」の単一見出し配下にポジ/ネガを
        // 箇条書きで並べる形から、「ポジティブな印象」「ネガティブな印象」を
        // 別見出しとして明確に分離した(依頼者指定、PDF版と同内容)。あわせて
        // AI利用の開示文言も削除した(依頼者指定 ―― UI/PDF上でAI利用を前面に
        // 出さない)。
        $positiveImpression = $wheel['positive_impression'] ?? null;
        $negativeImpression = $wheel['negative_impression'] ?? null;
        if ($wheel['key_message'] || $positiveImpression || $negativeImpression) {
            $section->addTextBreak(1);

            if ($wheel['key_message']) {
                $section->addText('サイト上の情報から想定されるキーメッセージ：'.$wheel['key_message']);
            }

            if ($positiveImpression) {
                $section->addText('ポジティブな印象：');
                $section->addText($positiveImpression);
            }
            if ($negativeImpression) {
                $section->addText('ネガティブな印象：');
                $section->addText($negativeImpression);
            }
        }
    }

    /**
     * 依頼AY-1/AY-3(2026-09-07): PDF版(lead-pdf.blade.php)で「○△－の対比表」
     * ページと「改善提案」ページを1ページへ統合したのに合わせ、Word版でも
     * 旧addComparisonSection()と旧addImprovementProposalSection()を1つの
     * addSection()(=1ページ相当の開始点、以降は明示的な改ページを入れない)
     * にまとめた。PhpWordはaddSection()の呼び出しごとに新しいページから
     * 始まる(セクション間の改ページを外す指定はできない)ため、2つの
     * addSection()に分けたままではPDF版のような「両方とも同じページに
     * 続ける」構成を再現できない ―― これが2つを1メソッド・1addSection()に
     * 統合した理由(依頼AY-4「WordはPDFと同一のレイアウトを再現できない
     * 場合、その旨と代替案を報告すること」に対する回答は実装報告に記載)。
     *
     * 2026-08-08: ●／－の2値から○△－の3値へ変更。○×は使わない(正解・
     * 不正解の記号であり、断り書きと矛盾する)。判定はBrandWheelSubElement
     * ComparisonComposerがすべて行う(AIには一切判定させない)。
     * $viewModel->subElementComparison(config順、24項目)が対比表の唯一の
     * 情報源。改善提案側は旧addImprovementProposalSectionと全く同じ情報源
     * ($viewModel->improvementFocus/improvementFocusSelfOnly等)・同じ選定
     * ロジック(無改修)を使う。
     *
     * 依頼AY-1: 自社が読み取れない場合のstatus_messageは、PDF版と同じく
     * 対比表・改善提案の両方に出していた重複を解消し、1回だけ出す。
     *
     * 依頼AY-3: 領域ごとの件数行(旧「{$label}：自社 x/y　比較 x/y」)は、
     * この統合ページでは上の24項目表が同じ情報をより詳細に示しており
     * 重複するため削除した(PDF版の.gapbar削除と同じ判断、モックアップに
     * 準拠)。
     */
    private function addComparisonAndImprovementSection(PhpWord $phpWord, ReportViewModel $viewModel): ?string
    {
        $section = $phpWord->addSection();
        $section->addTitle('診断結果 ―― 24項目の比較と改善提案', 1);

        if (($viewModel->brandWheelSelf['status'] ?? null) !== 'success' || ($viewModel->brandWheelSelf['axes'] ?? []) === []) {
            $section->addText((string) ($viewModel->brandWheelSelf['status_message'] ?? ''));

            return null;
        }

        $showCompetitorColumn = $this->competitorReadable($viewModel);

        $section->addText(
            '24項目それぞれについて、サイトに該当する記述があったかどうかを3段階で示しています。',
            ['size' => 9, 'italic' => true],
        );

        // 依頼AZ改(2026-09-07): 「比較結果サマリー」(PDF版の.cmpoverview
        // 相当)を削除した。すぐ下に追加するレーダー比較図が同じ情報
        // (自社・競合どちらが強いか)を視覚的に示しており、文章は図を
        // なぞって繰り返していただけのため(PDF版lead-pdf.blade.phpの
        // 同箇所コメント参照、依頼者確認済み)。PDF版・Word版どちらからも
        // 削除する。

        // 依頼AY-1・AY-4: PDF版のレーダー比較図(brandWheelRadarPngComparison)を
        // Word版にも追加する。Word版はこれまでレーダー図を一切埋め込んで
        // いなかった(自社/競合単独ページ含め、テキストと表のみ)が、PDF版の
        // 統合ページではレーダー図が主要な構成要素になったため、内容の一致
        // (依頼AY-4)を優先しWord版にも追加する。PhpWordのaddImage()は
        // ファイルパスを取る(生のPNGバイト列を直接渡せない)ため、一時
        // ファイルへ書き出してから渡す(generate()の.docx書き出しと同じ
        // tempnam()方式)。PhpWordは実際にはIOFactory::save()実行時に初めて
        // このファイルを読む(addImage()呼び出し時点では読まない)ため、
        // ここではまだ削除できない ―― 呼び出し元(generate())がsave()完了後に
        // 削除する(戻り値でパスを渡す、詳細はgenerate()側のコメント参照)。
        $radarTempPath = null;
        if ($showCompetitorColumn && $viewModel->brandWheelRadarPngComparison !== null) {
            $radarTempPath = tempnam(sys_get_temp_dir(), 'lead-report-radar-').'.png';
            file_put_contents($radarTempPath, $viewModel->brandWheelRadarPngComparison);
            $section->addTextBreak(1);
            $section->addImage($radarTempPath, ['width' => 220, 'height' => 160, 'alignment' => Jc::CENTER]);
        }

        $section->addTextBreak(1);
        $section->addText('凡例', ['bold' => true, 'size' => 9.5]);
        $section->addText('○　本文の記述から確認できた項目', ['size' => 9]);
        $section->addText('△　見出し・メニュー名などのラベルのみで、本文からは確認できなかった項目', ['size' => 9]);
        $section->addText('－　該当する記述が見つからなかった項目(『魅力が無い』という意味ではありません)', ['size' => 9]);

        $section->addTextBreak(1);
        $table = $section->addTable(['borderSize' => 6, 'borderColor' => 'cccccc', 'cellMargin' => 80]);
        $table->addRow();
        $table->addCell(2000)->addText('領域', ['bold' => true]);
        $table->addCell(3500)->addText('項目', ['bold' => true]);
        $table->addCell(1500)->addText('自社', ['bold' => true]);
        if ($showCompetitorColumn) {
            $table->addCell(1500)->addText('競合', ['bold' => true]);
        }

        foreach ($viewModel->subElementComparison as $item) {
            $table->addRow();
            $table->addCell(2000)->addText(self::GROUP_LABELS[$item['group']] ?? $item['group']);
            $table->addCell(3500)->addText($item['sub_name']);
            $table->addCell(1500)->addText($this->stateMark($item['self_state']));
            if ($showCompetitorColumn) {
                $table->addCell(1500)->addText($this->stateMark($item['competitor_state']));
            }
        }

        $section->addTextBreak(1);
        $legend = "合計　○自社サイト {$viewModel->selfTotalMatched} / {$viewModel->selfTotalMax}項目";
        if ($showCompetitorColumn) {
            $legend .= "　　○競合サイト {$viewModel->competitorTotalMatched} / {$viewModel->competitorTotalMax}項目";
        }
        $section->addText($legend, ['size' => 9.5, 'color' => '6B6767']);

        $refLegend = "(参考)　△自社 {$viewModel->selfTotalLabelOnly}件";
        if ($showCompetitorColumn) {
            $refLegend .= "　　△比較 {$viewModel->competitorTotalLabelOnly}件";
        }
        $section->addText($refLegend, ['size' => 9, 'color' => '8A8A8A']);

        $this->addImprovementProposalContent($section, $viewModel);

        return $radarTempPath;
    }

    /**
     * 2026-08-18: PDF版(lead-pdf.blade.php)の$competitorReadableと同じ判定
     * (競合サイトのURLの有無ではなく、実際にstatus==='success'かつaxesが
     * 空でないかで判定する)。以前は競合サイト分析結果セクション(generate())と
     * ○△－の対比表(addComparisonSection())がどちらもcompetitorWebsiteUrlの
     * 有無だけで分岐していたため、URLはあるが読み取れなかった場合(403等)に、
     * ほぼ空のセクションと「比較サイト 0 / 0項目」が出力されてしまっていた
     * (ゴディバの403調査から派生した誤記載の修正、依頼者指定)。
     */
    private function competitorReadable(ReportViewModel $viewModel): bool
    {
        return ($viewModel->brandWheelCompetitor['status'] ?? null) === 'success'
            && ($viewModel->brandWheelCompetitor['axes'] ?? []) !== [];
    }

    private function stateMark(string $state): string
    {
        return match ($state) {
            'matched' => '○',
            'label_only' => '△',
            default => '－',
        };
    }

    /**
     * 依頼R(2026-08-26): 「○と判定した根拠」ページ(PDF版lead-pdf.blade.php
     * と同内容)。$viewModel->selfEvidenceByAxis(ReportViewModelBuilder::
     * buildSelfEvidenceByAxis()、対比表と同じ軸順・下位要素順、自社の
     * matched項目のみ・evidenceが空文字の項目は含まない)が唯一の情報源。
     * 競合サイトの引用・discarded_sub_elements(棄却された引用)はそもそも
     * このフィールドに含まれない。
     *
     * 依頼AY-2(2026-09-07): 末尾の付録として最後(CTAの後)に置くよう移動した
     * (以前はcomparisonセクションの直後)。見出しに「【付録】」を付け、
     * 本編ではないことを示す(PDF版と同内容、内容自体は完全に無改修)。
     *
     * 空配列(matched=0件、または全項目のevidenceが空文字)の場合は
     * addSection()自体を呼ばない ―― 見出しだけの空セクション(空のページ)を
     * 作らない(PDF版の`@if ($viewModel->selfEvidenceByAxis !== [])`と同じ方針)。
     *
     * 1軸に複数件、matchedが多いサイト(実測: カヤック16件)で1ページに
     * 収まらない場合は、Wordの通常の文章送り(page-break-after相当の指定を
     * 一切していない)により自然に次ページへ続く ―― PDF版と同じ考え方
     * (.pageに高さを固定しないのと同様、Word側もこのセクション内で明示的な
     * 改ページを入れない)。
     */
    private function addEvidenceSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        if ($viewModel->selfEvidenceByAxis === []) {
            return;
        }

        $section = $phpWord->addSection();
        $section->addTitle('【付録】○と判定した根拠', 1);
        // 依頼AA(2026-08-27): PDF版と同じ出し分け(このレポート内に日本語訳が
        // 1件でもあるときだけ「(日本語訳を併記しています)」付きの説明文)。
        $intro = $viewModel->hasQuoteTranslations
            ? (string) config('brand_wheel.evidence_page_intro_with_translation')
            : (string) config('brand_wheel.evidence_page_intro');
        $section->addText($intro, ['size' => 9, 'color' => '6B6767']);

        $translationLabel = (string) config('brand_wheel.quote_translation_label');
        foreach ($viewModel->selfEvidenceByAxis as $axisGroup) {
            $section->addTextBreak(1);
            $section->addText($axisGroup['axis_name'], ['bold' => true, 'size' => 11.5, 'color' => '1D2088']);
            foreach ($axisGroup['items'] as $item) {
                $section->addText($item['sub_name'], ['bold' => true, 'size' => 9.5]);
                $section->addText('「'.$item['evidence'].'」', ['size' => 9]);
                // 依頼AA: 原文が主・訳が従であることが分かるよう、PDF版の
                // .quote-translationと同じ考え方(小さく・控えめに)。
                if (! empty($item['evidence_translation'])) {
                    $section->addText($translationLabel.'：'.$item['evidence_translation'], ['size' => 8, 'color' => '8A8A8A']);
                }
            }
        }
    }

    /**
     * 依頼AY-1/AY-3(2026-09-07): 旧addImprovementProposalSection()を、
     * addSection()/addTitle()を持たない中身だけのメソッドへ変更した
     * (呼び出し元のaddComparisonAndImprovementSection()が対比表と同じ
     * $sectionへ続けて書き込むことで、PDF版と同じ「1ページへ統合」を
     * 表現するため)。selfReadable判定・status_messageの表示は呼び出し元で
     * 既に済んでいる(このメソッドはselfReadable===trueの場合のみ呼ばれる)。
     *
     * PDF版6ページ目相当「改善提案」と同内容。ブランド・ホイール起点で
     * あること(技術的な指標から作らない、docs/lead-report-layout/README.md)。
     * ワンポイントは自社のみで判定可能なため常に自社の状態から出す。
     * 領域差・3項目は競合ありなら$viewModel->improvementFocus、競合なし
     * (または読み取れない)なら$viewModel->improvementFocusSelfOnly
     * (2026-08-10追加、いずれも決定的な規則で選定済み、△は未該当扱いのまま
     * 選定ロジック無改修)が唯一の情報源。両方nullの場合は「改善提案」の
     * 小見出しごと何も出さない(PDF版の@if ($viewModel->improvementFocus
     * !== null || $viewModel->improvementFocusSelfOnly !== null)と同じ)。
     *
     * 依頼AY-3: 領域ごとの件数行(旧「{$label}：自社 x/y　比較 x/y」)は、
     * 統合ページ上部の24項目表と重複するため削除した(PDF版と同じ判断)。
     *
     * 2026-08-08: 下部の技術的提案ブロック(「あわせて、サイトの作りに
     * ついて」)を削除した。4観点(測定結果)ページを削除したのに技術的提案
     * だけ残すのは整合が取れないため(ユーザー判断)。
     */
    private function addImprovementProposalContent(Section $section, ReportViewModel $viewModel): void
    {
        if ($viewModel->improvementFocus === null && $viewModel->improvementFocusSelfOnly === null) {
            return;
        }

        $section->addTextBreak(1);
        $section->addTitle('改善提案', 2);

        // 2026-08-17: ワンポイントの文言を改善提案AIの生成結果へ切り替える
        // (PDF版と同内容)。$viewModel->improvementOnePointは未生成/失敗時に
        // 既存の決定的ロジックへ自動フォールバック済み(ReportViewModelBuilder参照)。
        if ($viewModel->improvementOnePoint !== null) {
            $section->addText('【ワンポイント】'.$viewModel->improvementOnePoint, ['bold' => true]);
            $section->addTextBreak(1);
        }

        // 2026-08-18追加: ワンポイントの理由(依頼者指定の構成、PDF版と同内容)。
        if ($viewModel->improvementReason !== null) {
            $section->addText('理由：'.$viewModel->improvementReason, ['size' => 9.5]);
            $section->addTextBreak(1);
        }

        $focus = $viewModel->improvementFocus;
        if ($focus !== null) {
            // 依頼X-1〜X-4(2026-08-26、レポート42): 従来ここに直書きしていた
            // 文言(選ばれた領域名・件数)は、自社が3領域すべてで競合を上回る
            // ケースで「項目を0件挙げます。」「差が最も大きかったのは実際は
            // 自社優位の領域」という事実に反する出力になっていた
            // (PDF版・lead-pdf.blade.phpの同箇所コメント参照)。
            // BrandWheelImprovementFocusComposer::compose()側で候補の有無・
            // 差の符号に応じた文言を組み立てるようにしたため、ここでは
            // $focus['lead_text']をそのまま出すだけでよい。
            $section->addText($focus['lead_text']);

            // 依頼X-2: 候補が0件のときの「該当する項目はありませんでした」は
            // 廃止した(PDF版と同内容 ―― lead_textが既に状況を説明している)。
            if ($focus['items'] !== []) {
                // 依頼AH-2(2026-08-28): PDF版と同内容。①「追いつく」
                // (catch_up)/②「抜け出す」(breakout)をラベルで区別し、
                // ②には引用ブロック(比較サイトの記述)を出さない(比較サイトに
                // も記述が無いため引用するものが存在しない)。
                foreach ($focus['items'] as $i => $item) {
                    $section->addTextBreak(1);
                    $section->addText(($i + 1).'. '.$item['sub_name'], ['bold' => true]);
                    $section->addText(
                        (string) config('brand_wheel.improvement_card_type_labels.'.$item['type']),
                        ['size' => 8, 'bold' => true, 'color' => $item['type'] === 'catch_up' ? 'E95446' : '2C7F96'],
                    );
                    $section->addText($item['recommendation'], ['size' => 9, 'color' => '6B6767']);
                    $section->addText('（現在、サイトからは読み取れませんでした）', ['size' => 8, 'color' => '9A9A9A']);
                    if ($item['type'] === 'catch_up') {
                        $section->addText('競合サイトの記述：「'.$item['competitor_evidence'].'」');
                        // 依頼AA(2026-08-27): PDF版の.cmp-translationと同内容。
                        if (! empty($item['competitor_evidence_translation'])) {
                            $section->addText(
                                ((string) config('brand_wheel.quote_translation_label')).'：'.$item['competitor_evidence_translation'],
                                ['size' => 8, 'color' => '8A8A8A'],
                            );
                        }
                    }
                }

                // 2026-08-19: 「中長期的には：」の1行から、独立した見出し付き
                // ブロックへ格上げ(依頼者指定、PDF版の.diffboxと同内容)。
                // 依頼Q-2(2026-08-25): 「具体的に追加すべき情報」の箇条書き
                // (旧$viewModel->improvementRecommendedContents)は廃止した ――
                // 上のカード(sub_element_recommendationsの文面)と実質同じ
                // 内容を繰り返しており、「1ページ1推奨」の妨げになっていた
                // (依頼者指定、PDF版と同内容)。フィールド自体は変更していない、
                // 表示しないだけ。
                if ($viewModel->improvementMidTermAction !== null) {
                    $section->addTextBreak(1);
                    $section->addText('中長期の差別化ポイント', ['bold' => true, 'size' => 9.5, 'color' => '2C7F96']);
                    $section->addText($viewModel->improvementMidTermAction, ['size' => 9]);
                }
            }

            return;
        }

        $focusSelfOnly = $viewModel->improvementFocusSelfOnly;
        if ($focusSelfOnly === null) {
            return;
        }

        // 2026-08-10: 競合が無い(または読み取れない)診断向け。PDF版
        // (lead-pdf.blade.php、@elseif ($viewModel->improvementFocusSelfOnly))
        // と同内容。「比較サイトが無いため、領域ごとの比較はご用意できません。」
        // の1行だけでページの大半が空白になり、営業資料として成立しないという
        // 指摘(ユーザー)への対応。棒グラフ(groups)は常にBrandWheelImprovement
        // FocusComposer::composeSelfOnly()の決定的な規則で選定した数値のまま
        // (無改修)。
        //
        // 依頼Q-2(2026-08-25): PDF版と同じ出し分け。改善提案AIが
        // focus_sub_element_keysで有効な項目を挙げていれば3枚のカードはAI
        // 由来に差し替わり($focusSelfOnly['items_source'] === 'ai')、
        // 「最も少なかったのは〜」の一文(規則側の主張)は出さない ――
        // 棒グラフに数値は残るため情報は失われない。AI未生成/失敗/有効な
        // 項目0件のときは従来どおり規則由来の一文+カード('rule')のまま。
        $focusSelfOnlyItemsSource = $focusSelfOnly['items_source'] ?? 'rule';
        if ($focusSelfOnlyItemsSource === 'rule') {
            $selectedLabelSelf = self::GROUP_LABELS[$focusSelfOnly['selected_group']] ?? $focusSelfOnly['selected_group'];
            $section->addText(
                "3つの領域のうち、サイトの記述から読み取れた項目が最も少なかったのは「{$selectedLabelSelf}」でした。".
                'この領域から、候補者が知りたがる項目を'.count($focusSelfOnly['items']).'件挙げます。',
            );
        }

        if ($focusSelfOnly['items'] === []) {
            $section->addTextBreak(1);
            $section->addText('該当する項目はありませんでした');

            return;
        }

        foreach ($focusSelfOnly['items'] as $i => $item) {
            $section->addTextBreak(1);
            $section->addText(($i + 1).'. '.$item['sub_name'], ['bold' => true]);
            $section->addText($item['recommendation'], ['size' => 9, 'color' => '6B6767']);
            $section->addText($this->selfOnlyReasonLabel($item['self_reason']), ['size' => 8, 'color' => '9A9A9A']);
        }

        if ($viewModel->improvementMidTermAction !== null) {
            $section->addTextBreak(1);
            $section->addText('中長期の差別化ポイント', ['bold' => true, 'size' => 9.5, 'color' => '2C7F96']);
            $section->addText($viewModel->improvementMidTermAction, ['size' => 9]);
        }
    }

    /**
     * 2026-08-10: PDF版(lead-pdf.blade.php)の$selfOnlyReasonLabelクロージャと
     * 同内容。－(該当なし)と△(見出し・リンクラベルのみ)で文言を分ける
     * (ユーザー承認: 対比表ページの△の定義と一貫させるため、一律
     * 「記述が見つかりませんでした」にはしない)。
     */
    private function selfOnlyReasonLabel(string $reason): string
    {
        return $reason === 'label_only'
            ? '（現在、見出し・リンクラベルのみで、具体的な記述は見つかりませんでした）'
            : '（現在、サイトからは読み取れませんでした）';
    }

    /**
     * PDF版7ページ目(最終ページ)と同内容。2026-08-17: 長い説明文を削除し、
     * 営業CTAに集中させる(依頼者指定)。この新文言は2026-08-08時点の
     * 「旧CTA『他社比較(3〜5社)』は使わない」という制約と直接矛盾するが、
     * 今回の依頼文がこの文言を明示的に指定しているため優先する(PDF版の
     * コメント・実装報告に方針転換である旨を明記)。
     *
     * 連絡先は2026-08-10時点の方針を維持: https://leggenda-co.web-tools.biz/inquiry
     * のみを掲載し、外部フォームツール本体のURL・電話番号は掲載しない。
     * URLをそのまま長文表示せず、ボタン風のラベル付きリンクにする
     * (依頼者指定)。発行日は表紙と同じ$viewModel->generatedAtLabelを参照する。
     */
    private function addCallToActionSection(PhpWord $phpWord, ReportViewModel $viewModel): void
    {
        $section = $phpWord->addSection();

        $section->addTextBreak(4);
        $section->addText(
            'さらに3〜5社の競合採用サイトと比較し、御社が優先して改善すべき課題を整理しませんか？',
            ['bold' => true, 'size' => 18],
            ['alignment' => Jc::CENTER],
        );
        $section->addTextBreak(1);
        $section->addText(
            '詳細な比較結果をもとに、採用課題についてディスカッションします。',
            ['size' => 10.5, 'color' => '6B6767'],
            ['alignment' => Jc::CENTER],
        );

        $section->addTextBreak(3);
        // ボタン風のラベル付きリンク(URL文字列をそのまま表示しない、依頼者指定)。
        // PhpWordのTableスタイルにセンタリング用のalignmentは持たせず
        // (Word版はPDF版ほど厳密な中央寄せ検証を必要としない)、セル内の
        // テキストはJc::CENTERで中央寄せする。
        $btnTable = $section->addTable();
        $btnTable->addRow();
        $btnCell = $btnTable->addCell(4500, ['bgColor' => '1D2088', 'valign' => 'center']);
        $btnCell->addLink(
            'https://leggenda-co.web-tools.biz/inquiry',
            '競合比較について相談する',
            ['bold' => true, 'size' => 13, 'color' => 'FFFFFF', 'underline' => 'none'],
            ['alignment' => Jc::CENTER, 'spaceBefore' => 150, 'spaceAfter' => 150],
        );

        $section->addTextBreak(1);
        $section->addText(
            "お問い合わせの際は、本レポートの発行日（{$viewModel->generatedAtLabel}）と貴社名をお知らせください。",
            ['size' => 9.5, 'color' => '9A9A9A'],
            ['alignment' => Jc::CENTER],
        );
    }
}
