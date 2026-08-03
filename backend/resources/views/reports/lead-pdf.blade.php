<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>Webサイト診断レポート</title>
<style>
{{--
    2026-08-04: docs/lead-report-layout/report-layout-draft.htmlを移植元に
    全8ページへ全面書き直し(前置き/自社分析結果/読み取れた記述/他社比較/
    4観点/改善提案/ご相談 + 表紙)。配色はdocs/lead-report-layout/README.mdが
    唯一の定義元(レジェンダのコーポレートサイトから実測した値)。
    dompdfの制約により、flexbox・CSS grid・box-shadow・clip-pathは一切
    使わない(すべてtable+インラインスタイルで組む)。
--}}
    @page {
        size: A4 landscape;
        margin: 0;
    }
    @font-face {
        font-family: 'IPAexGothic';
        src: url('{{ $ipaexGothicFontPath }}') format('truetype');
        font-weight: normal;
        font-style: normal;
    }
    @font-face {
        font-family: 'IPAexGothic';
        src: url('{{ $ipaexGothicFontPath }}') format('truetype');
        font-weight: bold;
        font-style: normal;
    }
    {{--
        2026-08-04: font-weightは必ず'normal'か'bold'の文字列で指定すること。
        数値(600等)を使うと、上の@font-face(normal/boldの2つしか定義して
        いない)のどちらにもマッチせずdompdfがCJKグリフを持たない代替フォントへ
        フォールバックし、該当テキストが「?????」の羅列として描画される
        不具合が実PDF確認で見つかった(「サイトから読み取れた記述」ページの
        項目名列・表ヘッダーで発覚 ―― 同じページ内の他の列は正常だったため
        原因の特定に手間取った)。
    --}}

    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: 'IPAexGothic', sans-serif;
        color: #393636;
        font-size: 11pt;
        word-wrap: break-word;
        overflow-wrap: break-word;
    }
    p { margin: 0 0 2mm; word-wrap: break-word; overflow-wrap: break-word; }
    {{--
        高さ(height/min-height)は一切指定しない ―― 210mm(A4横の高さ)を
        指定すると、内容が短いページでも直後に空白ページが1枚挿入される
        不具合が実PDF確認で見つかった(2026-08-04)。@page { size: A4
        landscape } が既に1ページの大きさを決めているため、div側の高さ指定は
        不要。下部20mmはロゴ(.logo-mark)のクリアランス確保用
        (docs/lead-report-layout/README.md ―― 確保しないとキーメッセージの
        紺帯にロゴが重なる)。
    --}}
    .page { width: 297mm; padding: 14mm 16mm 20mm; position: relative; page-break-after: always; }
    .page.cta { page-break-after: auto; }
    h1 { font-size: 24pt; margin: 0 0 6mm; font-weight: normal; }
    h2 { font-size: 15pt; margin: 0 0 5mm; font-weight: normal; border-bottom: 1px solid #E0E0E0; padding-bottom: 2mm; }
    .cover { padding-top: 60mm; text-align: center; }
    .cover p { margin: 1.5mm 0; font-size: 11pt; color: #333; }
    {{--
        table-layout:fixedを既定にする ―― dompdfのtable-layout:auto(既定)は
        列幅をセル内容から再計算するため、画像やURLを含む列で意図した幅を
        超えてページ右端からはみ出す(2026-08-04の実PDF確認で発覚)。
        各テーブルの列幅はwidth(mm)で明示済みなので、fixedでその指定値を
        そのまま使わせる。
    --}}
    table { border-collapse: collapse; width: 100%; table-layout: fixed; }
    td { vertical-align: top; }
    .lead1 { font-size: 10pt; color: #6B6767; margin: 0 0 2mm; line-height: 1.4; }
    .sumhead { font-size: 10.5pt; font-weight: bold; margin: 0 0 1.5mm; }
    .sum { font-size: 9.5pt; line-height: 1.5; margin: 0; padding-left: 5mm; }
    .legend { text-align: center; font-size: 9pt; color: #5b5b5b; margin-top: 1mm; }
    .sw { display: inline-block; width: 9px; height: 9px; margin: 0 2px 0 10px; }
    .bandrow td { color: #fff; text-align: center; font-size: 10.5pt; font-weight: bold; padding: 1.5mm; }
    {{--
        2026-08-04: 16.66%のみだと右端の列がページ外へはみ出す不具合が
        あったため(自社ページの分析結果ページ、実PDF確認で発覚)、mm固定に
        している。6列×44.16mm=264.96mm(テーブル本体のwidth: 265mmと一致)。
        軸セルの高さは38mmを確保する(docs/lead-report-layout/README.md ――
        4項目すべて該当したケースで下の帯へめり込んだ実績があるため、必ず
        実PDFで目視確認すること)。
    --}}
    .axcell { width: 44.16mm; padding: 0 1mm; }
    .axhead { border: 1px solid #E0E0E0; background: #F5F5F5; text-align: center; font-size: 10pt; font-weight: bold; padding: 1mm; }
    .axbody { border: 1px solid #E0E0E0; border-top: none; padding: 2mm; height: 38mm; }
    .axcnt { font-size: 15pt; font-weight: bold; margin: 0 0 1.5mm; line-height: 1; }
    .axcnt small { font-size: 9pt; font-weight: normal; color: #6B6767; }
    .dots { margin: 0 0 2.5mm; }
    .dot { display: inline-block; width: 10px; height: 10px; margin-right: 3px; background: #DCDCDC; }
    .dot.on { background: #3A3FC0; }
    .hits2 { margin: 0; padding-left: 4mm; font-size: 9.5pt; line-height: 1.65; }
    .none2 { font-size: 9.5pt; color: #9A9A9A; margin: 0; }
    .darkband { background: #1D2088; color: #fff; padding: 2mm 5mm; margin-top: 2mm; }
    .darkband p { margin: 0.5mm 0; font-size: 10pt; line-height: 1.6; }
    {{--
        2026-08-04: 48%のみだと右側のカードがページ外へはみ出す不具合が
        あったため(他社ページ比較とのまとめページ、実PDF確認で発覚)、
        mm固定にしている。129.5mm×2列+6mm(スペーサー)=265mm。
    --}}
    .pane { border: 1px solid #1D2088; padding: 4mm; width: 129.5mm; }
    .pane h4 { margin: 0 0 2mm; font-size: 11pt; }
    .pane ul { margin: 0; padding-left: 5mm; font-size: 10pt; line-height: 1.9; }
    .arrow { text-align: center; font-size: 20pt; color: #1D2088; padding: 3mm 0; }
    .one { border: 1px solid #E0E0E0; padding: 4mm; }
    .one h4 { margin: 0 0 2mm; font-size: 11pt; }
    .one p { margin: 0; font-size: 10pt; line-height: 1.8; }
    {{--
        50%ではなくmm固定にする ―― 採用担当の視点(4観点)ページで、
        パーセンテージ幅だけの列がdompdfで右端からはみ出す不具合が
        見つかった(2026-08-04、右カラムのカードが用紙端で切れる)。
        132.5mm×2列=265mm(ページの実効幅と一致)。
    --}}
    .pcell { width: 132.5mm; padding: 0 3mm 6mm 0; }
    {{--
        height:100%は付けない ―― dompdfは制約の無い文脈で100%を「ページの
        残り高さ全体」に近い値として解決することがあり、カード1つがページ
        全体の高さまで異常に引き伸ばされて他の内容と重なる不具合が実PDF
        確認で見つかった(2026-08-04)。高さはpadding+内容にまかせる(auto)。
    --}}
    .pbox { border: 1px solid #E0E0E0; padding: 4mm; }
    .ptitle { font-size: 11.5pt; margin: 0 0 2mm; }
    .badge { display: inline-block; padding: 0.8mm 3mm; font-size: 9pt; }
    .badge.good { background: #e6f4ea; color: #1e6b38; }
    .badge.review { background: #fff8e1; color: #8a6d00; }
    .badge.warn { background: #fdecea; color: #9b2c22; }
    .badge.neutral { background: #eeeeee; color: #555555; }
    .pdesc { font-size: 10pt; line-height: 1.8; margin: 2.5mm 0 0; }
    {{-- .pcellと同じ理由でmm固定にする(2026-08-04)。88.3mm×3列=264.9mm。 --}}
    .reccell { width: 88.3mm; padding: 0 2mm 4mm 0; }
    .rectitle { font-size: 11pt; margin: 0 0 2mm; }
    .recdesc { font-size: 9.5pt; line-height: 1.8; margin: 0 0 3mm; }
    .recmeta { font-size: 9pt; color: #5b5b5b; margin: 0; }
    .recbox { border: 1px solid #E0E0E0; padding: 4mm; height: 46mm; }
    {{--
        position:absoluteは文章には使わない ―― 実PDF確認で、2行に折り返す
        境界の文字がまれに欠落する不具合が見つかった(2026-08-04)。通常
        フロー(position指定なし)にした上で、ロゴ(右下30mm)と重ならないよう
        右側52mmを空ける(docs/lead-report-layout/README.mdの指定と同じ
        クリアランスを、テキスト破損の無い通常フローで実現する)。
    --}}
    .foot { margin-top: 6mm; font-size: 8.5pt; color: #7a7a7a; line-height: 1.6; max-width: 213mm; }
    .cta { text-align: center; padding-top: 45mm; }
    .cta p { font-size: 12pt; line-height: 2; }

    {{-- 「採用ブランドの捉え方」前置きページ(固定の説明図)。 --}}
    .introlead { font-size: 11.5pt; margin: 0 0 4mm; }
    .grouptbl { margin-bottom: 5mm; }
    .grouptbl td { padding: 2mm 0; vertical-align: middle; }
    .gcell { width: 34mm; text-align: center; font-size: 10pt; font-weight: bold; padding: 2.5mm 1mm; }
    .gdesc { width: 105mm; font-size: 10pt; line-height: 1.7; padding-left: 4mm; }
    .introbody { font-size: 10.5pt; line-height: 1.9; margin: 0 0 3.5mm; }
    .introcaution { font-size: 9.5pt; line-height: 1.8; color: #5b5b5b; border-top: 1px solid #E0E0E0; padding-top: 3mm; margin: 0; }

    {{-- 自社ページの分析結果ページの合計件数ボックス。 --}}
    {{--
        2026-08-04: CSSのpadding-rightで列間の余白を作ると、table-layout:fixed
        下でdompdfが右端の列(レーダー画像)を宣言幅より狭く解決し、画像の
        右側が欠けて描画される不具合が実PDF確認で見つかった。padding-rightは
        使わず、.pane等と同じ実績のある方式(列間に幅固定のスペーサーtdを
        挟む)で余白を作る。
    --}}
    .statrow td { vertical-align: top; }
    .statbox { border: 1px solid #E0E0E0; padding: 3.5mm 5mm; }
    .statbox .lab { font-size: 9.5pt; color: #6B6767; margin: 0 0 1.5mm; }
    .statbox .num { font-size: 26pt; font-weight: bold; line-height: 1; margin: 0; }
    .statbox .num small { font-size: 11pt; font-weight: normal; color: #6B6767; }
    .swatch { display: inline-block; width: 9px; height: 9px; margin-right: 4px; }

    {{-- 「サイトから読み取れた記述」ページ(evidence一覧)。 --}}
    .evtbl { border-collapse: collapse; width: 100%; }
    .evtbl th { background: #F5F5F5; border: 1px solid #E0E0E0; font-size: 10pt; font-weight: bold; text-align: left; padding: 2.5mm 3mm; }
    .evtbl td { border: 1px solid #E0E0E0; padding: 2.5mm 3mm; font-size: 10pt; line-height: 1.7; vertical-align: top; }
    .evaxis { width: 34mm; font-weight: bold; }
    .evsub { width: 42mm; color: #393636; }
    .evq { width: 189mm; color: #393636; }
    .evbar { display: inline-block; width: 4px; height: 11px; margin-right: 5px; vertical-align: -1px; }

    {{-- ロゴ(コーポレートサイト、512x94)。 --}}
    .logo-cover { display: block; margin: 0 auto 10mm; width: 72mm; }
    {{--
        2026-08-04: right:16mmで指定すると、ロゴ画像が右端で「LE」までしか
        描画されず途中で切れる不具合が実PDF確認で見つかった(.footのテキスト
        折り返し不具合とは別に、dompdfのposition:absoluteでは`right`指定自体が
        信頼できないことが判明)。leftへ変更して回避する。
        left = ページ幅297mm - 右余白16mm - ロゴ幅30mm = 251mm。
    --}}
    .logo-mark { position: absolute; left: 251mm; bottom: 7mm; width: 30mm; opacity: .9; }
    .cta .logo-cover { width: 60mm; margin-bottom: 8mm; }
</style>
</head>
<body>

@php
    $selfWheel = $viewModel->brandWheelSelf;
    $competitorWheel = $viewModel->brandWheelCompetitor;
    $selfReadable = ($selfWheel['status'] ?? null) === 'success' && ! empty($selfWheel['axes']);
    $competitorReadable = ($competitorWheel['status'] ?? null) === 'success' && ! empty($competitorWheel['axes']);
    $comparison = $viewModel->brandWheelComparison;
    $hasComparisonContent = ! empty($comparison['self_points']) || ! empty($comparison['competitor_points']) || $comparison['one_point'] !== null;

    // 2026-08-04: グループ名から色名(青/緑/赤)を外している ――
    // 配色をレジェンダに合わせた結果、緑が青緑に変わり色名と実際の色が
    // 食い違うため(docs/lead-report-layout/README.md参照)。
    $groupBands = [
        'company_appeal' => ['label' => '会社の魅力', 'color' => '#1D2088', 'tint' => '#D3D4EC'],
        'company_distance' => ['label' => '会社との距離', 'color' => '#2C7F96', 'tint' => '#CFE3EA'],
        'job_appeal' => ['label' => '仕事の魅力', 'color' => '#C03A28', 'tint' => '#F7DCD7'],
    ];

    $badgeClassByStatus = [
        'good' => 'good',
        'needs_review' => 'review',
        'needs_improvement' => 'warn',
        'not_measured' => 'neutral',
        'not_applicable' => 'neutral',
        'not_detected' => 'neutral',
        'unavailable' => 'neutral',
    ];

    // 合計件数(「N / 24項目」)は固定値ではなく、axesのmax_count合計から
    // 算出する(config('brand_wheel.axes')の下位要素数が変わっても
    // コード変更無しに追従させるため、2026-08-04)。
    $selfTotalMatched = array_sum(array_column($selfWheel['axes'] ?? [], 'matched_count'));
    $selfTotalMax = array_sum(array_column($selfWheel['axes'] ?? [], 'max_count'));
    $competitorTotalMatched = array_sum(array_column($competitorWheel['axes'] ?? [], 'matched_count'));
    $competitorTotalMax = array_sum(array_column($competitorWheel['axes'] ?? [], 'max_count'));
@endphp

{{-- 1. 表紙 --}}
<div class="page cover">
    <img class="logo-cover" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <h1>Webサイト診断レポート</h1>
    <p>{{ $viewModel->companyDisplayName }}</p>
    <p>対象サイト: {{ $viewModel->selfWebsiteUrl }}</p>
    @if ($viewModel->competitorWebsiteUrl)
        <p>比較サイト: {{ $viewModel->competitorWebsiteUrl }}</p>
    @endif
    <p>{{ $viewModel->generatedAtLabel }}</p>
    @if ($viewModel->isPartial)
        <p style="font-size: 9pt; color: #7a7a7a;">一部のデータは取得できませんでしたが、取得できた範囲での診断結果です。</p>
    @endif
</div>

{{--
    2. 採用ブランドの捉え方 ―― ブランド・ホイール(前置き)。
    分析結果に依存しない固定の説明図(backend/resources/images/
    brand-wheel-framework.png)。BrandWheelHexagonRendererは通さない ――
    毎回rsvg-convertを走らせる意味が無い静的アセットのため
    (config('brand_wheel.axes.*.sub_elements')を変更したらこの画像も
    作り直す必要がある、config/brand_wheel.php・README「リリース前
    チェックリスト」参照)。

    この診断で最も誤解を招きやすい点(読み取れなかった=魅力が無い、では
    ない)を、結果を見せる前にここで明示する。この一文は短縮・削除しない。
--}}
<div class="page">
    <h2>採用ブランドの捉え方 ―― ブランド・ホイール</h2>
    <table style="width: 265mm;"><tr>
        <td style="width: 126mm; padding-right: 8mm;">
            <img src="data:image/png;base64,{{ $brandWheelFrameworkImageBase64 }}" style="width: 124mm;">
        </td>
        <td style="width: 139mm; vertical-align: top; padding-top: 4mm;">
            <p class="introlead">採用ブランドは、大きく3つの領域に分けて捉えます。</p>
            <table class="grouptbl"><tr>
                <td class="gcell" style="background: {{ $groupBands['company_appeal']['tint'] }};">会社の魅力</td>
                <td class="gdesc">その会社が何を目指し、どれだけの実績・規模を持っているか。<br><b>活動的魅力</b>・<b>資産的魅力</b></td>
            </tr><tr>
                <td class="gcell" style="background: {{ $groupBands['company_distance']['tint'] }};">会社との距離</td>
                <td class="gdesc">どんな経営で、どんな人たちが、どんな環境で働いているか。<br><b>経営スタイル</b>・<b>就業環境</b></td>
            </tr><tr>
                <td class="gcell" style="background: {{ $groupBands['job_appeal']['tint'] }};">仕事の魅力</td>
                <td class="gdesc">その仕事に就くと、何が得られるか。<br><b>情緒的便益</b>・<b>金銭的便益</b></td>
            </tr></table>
            <p class="introbody">6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。中心の<b>Core Value(約束する価値)</b>は、その24項目を貫く「この会社が候補者に約束するもの」にあたります。</p>
            <p class="introbody">本レポートでは、<b>この24項目のうち何件が、サイトの記述から読み取れたか</b>を数えています。点数付けではなく、件数の集計です。</p>
            <p class="introcaution">読み取れなかった項目は、その魅力が「無い」という意味ではありません。サイトにそう書かれていない、というだけです。また、採用ブランドは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNSなども併せて構築するものです。今回はそのうちサイトの記述のみを拝見しています。</p>
        </td>
    </tr></table>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
</div>

{{-- 3. 自社ページの分析結果 --}}
<div class="page">
    <h2>自社ページの分析結果</h2>

    @if (! $selfReadable)
        {{-- 6項目すべて0件の図・表は「魅力のない会社」の意味になるため出さない。
             理由の文言はconfig('brand_wheel.status_messages')が唯一の定義元。 --}}
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @else
        <p class="lead1">6つの項目それぞれについて、該当する内容がサイトの記述から何件読み取れたかを集計しています(点数ではありません)。<br>解析したURL：{{ $selfWheel['analyzed_url'] }}</p>

        {{--
            2026-08-04: 「サマリー」の箇条書きはBrandWheelComparisonSummaryComposerが
            軸数に応じて可変長(0件の軸1つにつき1件、最大で数件)で生成するため、
            統計ボックス・レーダー画像と同じ行に置くと、テキストの折り返し量
            次第でその行全体の高さが不安定になり、実データ(自社側5件)で
            軸カード表・キーメッセージ帯がまるごと次ページへあふれる不具合が
            実PDF確認で見つかった(ダミーデータの3件では再現しなかった)。
            統計・レーダーの行とサマリーを別ブロックに分離し、影響を切り離す。
        --}}
        <table class="statrow" style="width: 265mm;"><tr>
            <td style="width: 50mm;">
                <div class="statbox">
                    <p class="lab"><span class="swatch" style="background: #3A3FC0;"></span>自社サイト</p>
                    <p class="num">{{ $selfTotalMatched }}<small> / {{ $selfTotalMax }}項目</small></p>
                </div>
            </td>
            <td style="width: 4mm;"></td>
            @if ($competitorReadable)
                <td style="width: 50mm;">
                    <div class="statbox">
                        <p class="lab"><span class="swatch" style="background: #E95446;"></span>競合サイト</p>
                        <p class="num">{{ $competitorTotalMatched }}<small> / {{ $competitorTotalMax }}項目</small></p>
                    </div>
                </td>
                <td style="width: 4mm;"></td>
            @endif
            <td style="width: {{ $competitorReadable ? '77mm' : '131mm' }};"></td>
            <td style="width: 80mm;">
                @if ($viewModel->brandWheelRadarPng)
                    {{-- レーダー図のviewBoxは380x276(縦横比380:276)。dompdfは
                         widthのみ指定だと縦横比を正しく保持しないことがあるため、
                         heightも明示して指定どおりの比率で描画させる。 --}}
                    <img src="data:image/png;base64,{{ base64_encode($viewModel->brandWheelRadarPng) }}" style="width: 76mm; height: 55mm;">
                    <div class="legend">
                        <span class="sw" style="background: #3A3FC0;"></span>自社サイト
                        @if ($competitorReadable)
                            <span class="sw" style="background: #E95446;"></span>競合サイト
                        @endif
                    </div>
                @endif
            </td>
        </tr></table>

        <p class="sumhead" style="margin-top: 3mm;">サマリー</p>
        <ul class="sum">
            @foreach ($comparison['self_points'] as $point)
                <li>{{ $point }}</li>
            @endforeach
        </ul>

        <table class="bandrow" style="margin-top: 3mm;"><tr>
            @foreach ($groupBands as $band)
                <td colspan="2" style="background: {{ $band['color'] }};">{{ $band['label'] }}</td>
            @endforeach
        </tr></table>

        <table style="width: 265mm; margin-top: 1mm;"><tr>
            @foreach ($selfWheel['axes'] as $axis)
                <td class="axcell">
                    <div class="axhead">{{ $axis['name'] }}</div>
                    <div class="axbody">
                        <p class="axcnt">{{ $axis['matched_count'] }}<small> / {{ $axis['max_count'] }}件</small></p>
                        {{--
                            この四角は「壊れて出ていない」のか「調べた結果0件」
                            なのかを区別するための表示。matched_count===0でも
                            省略しない(docs/lead-report-layout/README.md)。
                            四角の数はmax_countから生成する(固定値で書かない)。
                        --}}
                        <p class="dots">
                            @for ($i = 1; $i <= $axis['max_count']; $i++)
                                <span class="dot {{ $i <= $axis['matched_count'] ? 'on' : '' }}"></span>
                            @endfor
                        </p>
                        @if (count($axis['matched_sub_elements']) > 0)
                            <ul class="hits2">
                                @foreach ($axis['matched_sub_elements'] as $sub)
                                    <li>{{ $sub['name'] }}</li>
                                @endforeach
                            </ul>
                        @else
                            {{-- 「読み取れた内容はありません」は使わない
                                 (内容が無い会社、と読めるため)。 --}}
                            <p class="none2">該当する記述は見つかりませんでした</p>
                        @endif
                    </div>
                </td>
            @endforeach
        </tr></table>

        @if ($selfWheel['key_message'] || $selfWheel['impression'])
            <div class="darkband">
                @if ($selfWheel['key_message'])
                    <p><b>キーメッセージ：</b>{{ $selfWheel['key_message'] }}</p>
                @endif
                @if ($selfWheel['impression'])
                    <p><b>AI解析による印象：</b>{{ $selfWheel['impression'] }}</p>
                @endif
            </div>
        @endif
    @endif
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
</div>

{{--
    4. サイトから読み取れた記述(evidence一覧)。該当0件の場合はこのページ
    自体を出さない(見出しと空の表だけが残る状態を作らない ―― 画面側で
    同じ失敗を一度している)。evidenceは要約・整形・省略記号での短縮を
    一切しない ―― 原文との部分文字列照合を通ったものだけが残っている、
    というのがこのページの価値(docs/lead-report-layout/README.md)。
--}}
@if (count($viewModel->selfBrandWheelEvidenceItems) > 0)
<div class="page">
    <h2>サイトから読み取れた記述</h2>
    <p class="lead1">前ページで「該当あり」とした項目について、サイトのどの記述を根拠にしたかを記載しています。抜粋はサイト上の文章をそのまま引用したもので、要約や言い換えは含みません。</p>
    <table class="evtbl" style="width: 265mm;">
        <tr>
            <th style="width: 34mm;">項目</th>
            <th style="width: 42mm;">何について</th>
            <th style="width: 189mm;">サイトからの記述</th>
        </tr>
        @foreach ($viewModel->selfBrandWheelEvidenceItems as $item)
            <tr>
                <td class="evaxis"><span class="evbar" style="background: {{ $groupBands[$item['group']]['color'] ?? '#999999' }};"></span>{{ $item['axis_name'] }}</td>
                <td class="evsub">{{ $item['sub_element_name'] }}</td>
                <td class="evq">「{{ $item['evidence'] }}」</td>
            </tr>
        @endforeach
    </table>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
</div>
@endif

{{-- 5. 他社ページ比較とのまとめ --}}
<div class="page">
    <h2>他社ページ比較とのまとめ</h2>

    @if (! $hasComparisonContent)
        <p>{{ $selfWheel['status_message'] ?? '比較のまとめを今回はご用意できませんでした。' }}</p>
    @else
        <table style="width: 265mm;"><tr>
            @if (! empty($comparison['self_points']))
                <td class="pane">
                    <h4>【自社ページ】</h4>
                    <ul>
                        @foreach ($comparison['self_points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </td>
                <td style="width: 6mm;"></td>
            @endif
            @if (! empty($comparison['competitor_points']))
                <td class="pane">
                    <h4>【他社ページ】</h4>
                    <ul>
                        @foreach ($comparison['competitor_points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                </td>
            @endif
        </tr></table>

        @if ($comparison['one_point'])
            <div class="arrow">▼</div>
            <div class="one">
                <h4>【ワンポイント】</h4>
                <p>{{ $comparison['one_point']['text'] }}</p>
            </div>
        @endif
    @endif

    {{--
        1つの<p>にまとめず2文に分ける ―― 1つの長い段落のまま2行に折り返す
        と、折り返し境界の文字がまれに欠落する不具合が実PDF確認で見つかった
        (2026-08-04)。文単位で<p>を分けることで折り返し位置自体を変え、回避する。
    --}}
    <p class="foot">ブランド・ホイールは本来、サイトだけでなくグループインタビュー・口コミ・内定者/辞退者インタビュー・説明会・SNSなども併せて構築するものです。今回はそのうちサイトの記述のみを拝見しています。</p>
    <p class="foot" style="margin-top: 1mm;">キーメッセージと印象の読み取りにはAIを使用しています。</p>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
</div>

{{-- 6. 採用担当の視点で見た診断結果(4観点。判定と理由1文のみ、個別指標名は出さない) --}}
<div class="page">
    <h2>採用担当の視点で見た診断結果</h2>
    <p class="lead1">4つの観点それぞれについて、判定と、その理由を一言で記載しています。</p>

    <table style="width: 265mm;">
        @foreach (array_chunk($viewModel->perspectives, 2) as $row)
            <tr>
                @foreach ($row as $perspective)
                    <td class="pcell">
                        <div class="pbox">
                            <p class="ptitle">{{ $perspective['heading'] }}</p>
                            <span class="badge {{ $badgeClassByStatus[$perspective['status']] ?? 'neutral' }}">
                                {{ \App\Services\Lead\LeadPerspectiveComposer::statusLabel($perspective['status']) }}
                            </span>
                            <p class="pdesc">{{ $perspective['one_liner'] }}</p>
                        </div>
                    </td>
                @endforeach
            </tr>
        @endforeach
    </table>

    <p class="foot">
        取得できなかった項目は0点として扱わず、算出の対象から外しています(測定カバー率 {{ number_format($viewModel->selfScore['coverage_rate'], 1) }}%／確信度 {{ number_format($viewModel->selfScore['confidence_rate'], 1) }}%)。
    </p>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
</div>

{{-- 7. 改善提案 --}}
<div class="page">
    <h2>改善提案</h2>
    <p class="lead1">特に改善効果が見込まれる項目を、優先度の高い順に記載しています。</p>

    @if (count($viewModel->topRecommendations) === 0)
        <p>現時点で優先度の高い改善提案はありません。</p>
    @else
        <table style="width: 265mm;">
            @foreach (array_chunk($viewModel->topRecommendations, 3) as $row)
                <tr>
                    @foreach ($row as $recommendation)
                        <td class="reccell">
                            <div class="recbox">
                                <p class="rectitle">{{ $recommendation->title }}</p>
                                <p class="recdesc">{{ $recommendation->description }}</p>
                                <p class="recmeta">優先度: {{ $recommendation->priorityLabel }}　影響度: {{ $recommendation->impactLabel }}　対応工数: {{ $recommendation->effortLabel }}</p>
                            </div>
                        </td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    @endif
</div>

{{-- 8. ご相談 --}}
<div class="page cta">
    <img class="logo-cover" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <h2>より詳しい診断・ご相談はこちら</h2>
    <p>今回は自社サイト{{ $viewModel->competitorWebsiteUrl ? '・比較サイト1社' : '' }}の簡易診断結果です。</p>
    <p>他社比較(3〜5社)や、詳細な改善提案については、担当者までお気軽にご相談ください。</p>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
</div>

</body>
</html>
