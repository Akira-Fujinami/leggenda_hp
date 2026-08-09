<!DOCTYPE html>
<html lang="ja">
<head>
<meta charset="utf-8">
<title>Webサイト診断レポート</title>
<style>
{{--
    2026-08-04: docs/lead-report-layout/report-layout-draft.htmlを移植元に
    全9ページへ全面書き直し(表紙/前置き/自社分析結果/読み取れた記述/4観点/
    24項目の対比/触れられていなかった項目/改善提案/ご相談)。旧「他社ページ
    比較とのまとめ」は削除(所見が24項目の対比ページの言い換えになっていた
    ため、docs/lead-report-layout/README.md)。配色・文言はREADMEが唯一の
    定義元(レジェンダのコーポレートサイトから実測した値)。
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
    {{-- 2026-08-08: 上余白を14mm→10mmに縮小(全ページ共通)。7ページ構成
         (自社/競合単独ページ・○△－対比表ページ)で実データの内容量が
         増え、既存の14mmでは複数ページでキーメッセージ帯末尾が1行だけ
         次ページへ孤立する不具合が実PDF確認で見つかったため、まず
         全ページ共通の余白から縮小して確保した。 --}}
    .page { width: 297mm; padding: 6mm 16mm 20mm; position: relative; page-break-after: always; }
    .page.cta { page-break-after: auto; }
    h1 { font-size: 24pt; margin: 0 0 6mm; font-weight: normal; }
    {{--
        2026-08-04: width:265mmを明示する ―― `.page`はbox-sizing:border-box+
        widthあり+paddingありの組み合わせで、子要素がwidth指定無し(auto/
        暗黙の100%)の場合、dompdfがpaddingを差し引かずに`.page`の宣言幅
        (297mm)をそのまま基準にしてしまう不具合が実PDF確認で見つかった
        (h2のborder-bottomの線が右へ16mmはみ出し、ページ右端で見切れていた)。
        `.page`直下の要素はここまで一貫してmm固定幅にしてきた対策と同じ理由で、
        h2にも明示する。
    --}}
    h2 { width: 265mm; font-size: 15pt; margin: 0 0 3mm; font-weight: normal; border-bottom: 1px solid #E0E0E0; padding-bottom: 1.5mm; }
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
    {{--
        2026-08-04: 以下、`.page`直下に置く説明文(lead段落)にはすべて
        width: 265mmを明示する。h2/.darkbandと同じ理由(CSS冒頭のh2コメント
        参照)に加え、この場合は「見えないボックスが広すぎる」だけでなく
        実際に文字が回り込まず、ページ右端(297mm)を超えた分の文字が
        物理的に見切れる(欠落する)不具合として実PDF確認で見つかった
        (24項目の対比・触れられていなかった項目ページで、説明文の最後の
        数文字が消えていた)。widthを与えることで正しい265mm幅で折り返す。
    --}}
    .lead1 { width: 265mm; font-size: 9.5pt; color: #6B6767; margin: 0 0 1mm; line-height: 1.25; }
    .sumhead { width: 265mm; font-size: 10pt; font-weight: bold; margin: 0 0 1mm; }
    .sum { font-size: 8.5pt; line-height: 1.2; margin: 0; padding-left: 5mm; }
    .legend { text-align: center; font-size: 9pt; color: #5b5b5b; margin-top: 1mm; }
    .sw { display: inline-block; width: 9px; height: 9px; margin: 0 2px 0 10px; }
    .bandrow td { color: #fff; text-align: center; font-size: 10pt; font-weight: bold; padding: 1mm; }
    {{--
        2026-08-04: 16.66%のみだと右端の列がページ外へはみ出す不具合が
        あったため(自社ページの分析結果ページ、実PDF確認で発覚)、mm固定に
        している。6列×44.16mm=264.96mm(テーブル本体のwidth: 265mmと一致)。
        軸セルの高さは38mmを確保する(docs/lead-report-layout/README.md ――
        4項目すべて該当したケースで下の帯へめり込んだ実績があるため、必ず
        実PDFで目視確認すること)。
    --}}
    .axcell { width: 44.16mm; padding: 0 1mm; }
    .axhead { border: 1px solid #E0E0E0; background: #F5F5F5; text-align: center; font-size: 9.5pt; font-weight: bold; padding: 0.7mm; }
    .axbody { border: 1px solid #E0E0E0; border-top: none; padding: 2mm; height: 38mm; }
    .axcnt { font-size: 14pt; font-weight: bold; margin: 0 0 1mm; line-height: 1; }
    .axcnt small { font-size: 9pt; font-weight: normal; color: #6B6767; }
    .dots { margin: 0 0 1.5mm; }
    .dot { display: inline-block; width: 10px; height: 10px; margin-right: 3px; background: #DCDCDC; }
    .dot.on { background: #3A3FC0; }
    .hits2 { margin: 0; padding-left: 4mm; font-size: 8.5pt; line-height: 1.3; }
    .none2 { font-size: 9.5pt; color: #9A9A9A; margin: 0; }
    {{-- widthを明示する理由はh2と同じ(2026-08-04、上記コメント参照)。 --}}
    {{--
        2026-08-09: page-break-inside: avoidを追加(ユーザー承認の「上限付き」
        案)。実データ検証で、紺帯の残り余白が2mm程度しかないケースがあり、
        中途半端に1〜2行だけ次ページへ孤立する不具合が実PDF確認で見つかった。
        上限(件数・文字数)と2列化で紺帯の最大高さ自体を縮めた上で、万一それ
        でも収まらない場合は「途中で割れる」のではなく「丸ごと次ページへ」
        という失敗の仕方に倒す(dompdfはpage-break-insideを尊重する)。
    --}}
    .darkband { width: 265mm; background: #1D2088; color: #fff; padding: 1.3mm 5mm; margin-top: 1mm; page-break-inside: avoid; }
    .darkband p { margin: 0.4mm 0; font-size: 10pt; line-height: 1.4; }
    {{--
        2026-08-08: 「AI解析による候補者に与える印象」を1文の地の文から
        2〜4件の列挙(impression)へ変更したことに伴うリスト表示。
        2026-08-09: 1列(縦積み)から2列(table)へ変更 ―― BrandWheelLeadResponse
        Composerが最大3件・各45文字に切り詰めるため、2列にすることで最大でも
        2行に収まる(縦積みだと最大3行)。
    --}}
    .impressiontbl { margin: 0.3mm 0 0; width: 255mm; table-layout: fixed; }
    .impressiontbl td { font-size: 9pt; line-height: 1.3; padding: 0; width: 127.5mm; vertical-align: top; }
    {{-- AI開示文をdarkbandの内側に置く際の控えめな配色(白地の.footとは別)。 --}}
    .darkfoot { margin: 1mm 0 0; font-size: 8pt; color: #B9BBDA; line-height: 1.3; }
    {{--
        position:absoluteは文章には使わない ―― 実PDF確認で、2行に折り返す
        境界の文字がまれに欠落する不具合が見つかった(2026-08-04)。通常
        フロー(position指定なし)にした上で、ロゴ(右下30mm)と重ならないよう
        右側52mmを空ける(docs/lead-report-layout/README.mdの指定と同じ
        クリアランスを、テキスト破損の無い通常フローで実現する)。
    --}}
    .foot { margin-top: 6mm; font-size: 8.5pt; color: #7a7a7a; line-height: 1.6; max-width: 213mm; }
    {{--
        2026-08-04: 旧「ご相談」ページ(2行だけの短い文面)向けの
        .cta/.cta p(padding-top:45mmで縦中央寄せ・p全体を12pt化)は、
        9ページ目を.ctawrap配下の新デザイン(ロゴ・見出し・3ブロック・
        フッター)に全面差し替えた際の削除漏れだった。残したままだと
        45mmの上余白でフッター最終行が2ページ目に溢れる不具合と、
        `.cta p`(specificity: class+type)が`.ctah`等(class単体)より
        優先されてしまう不具合の両方を実PDF確認で発見したため削除する。
        新デザインの余白・文字サイズは.ctawrap配下の各クラス
        (.ctah/.ctasub/.ctabox/.ctafoot等)がすべて個別に持っている。
    --}}

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
        使わず、実績のある方式(列間に幅固定のスペーサーtdを挟む)で余白を作る
        (画像を含む行にのみ適用。テキストのみの行はpadding方式で問題ない)。
    --}}
    .statrow td { vertical-align: top; }
    .statbox { border: 1px solid #E0E0E0; padding: 3.5mm 5mm; }
    .statbox .lab { font-size: 9.5pt; color: #6B6767; margin: 0 0 1.5mm; }
    .statbox .num { font-size: 26pt; font-weight: bold; line-height: 1; margin: 0; }
    .statbox .num small { font-size: 11pt; font-weight: normal; color: #6B6767; }
    .swatch { display: inline-block; width: 9px; height: 9px; margin-right: 4px; }

    {{-- 「○△－の対比表」ページ。2026-08-08: ●／－の2値から○△－の3値へ変更。 --}}
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    .vslead { width: 265mm; font-size: 9.5pt; color: #6B6767; margin: 0 0 1.5mm; line-height: 1.3; }
    {{--
        凡例は表の近くに必ず置く(対比表ページの意味を誤読させないため
        ―― ○△－は正解/不正解の記号ではない、2ページ目の断り書きと矛盾
        しないこと。ユーザー指定)。
    --}}
    {{-- 2026-08-10: width:265mm→165mm(レーダーと横並びにしたため、CSS冒頭の
         h2コメントと同じ理由で明示が必要)。 --}}
    .cmplegend { width: 165mm; border: 1px solid #E0E0E0; background: #F5F5F5; padding: 1.8mm 4mm; margin: 0; }
    .cmplegend p { font-size: 9pt; color: #393636; line-height: 1.4; margin: 0; }
    .cmplegend .mk { display: inline-block; width: 5mm; font-weight: bold; }
    .vscell { width: 88.3mm; padding: 0 2mm 4mm 0; vertical-align: top; }
    .grpbar { color: #fff; font-size: 10pt; font-weight: bold; text-align: center; padding: 1.2mm; }
    .vstbl th { font-size: 9pt; font-weight: bold; padding: 1.2mm 1mm; border-bottom: 1px solid #E0E0E0; color: #6B6767; text-align: center; }
    .vstbl th.sub { text-align: left; }
    .vstbl td { font-size: 9pt; padding: 1.3mm 1mm; border-bottom: 1px solid #EFEFEF; }
    .vstbl td.sub { text-align: left; }
    .vstbl td.mk { text-align: center; width: 13mm; font-size: 11pt; }
    .mkon { color: #1D2088; font-weight: bold; }
    .mkon.cp { color: #E95446; }
    {{-- △(見出し・リンクラベルのみ)。○(mkon、自社紺/競合朱)・－(mkoff、
         淡灰)のどちらとも視覚的に紛れない中間色(アンバー)にする。 --}}
    .mktri { color: #B8860B; font-weight: bold; }
    .mkoff { color: #BFBFBF; }
    .vslegend { width: 265mm; font-size: 9.5pt; color: #6B6767; margin: 1.5mm 0 0; }
    .vsreflegend { width: 265mm; font-size: 9pt; color: #8A8A8A; margin: 0.5mm 0 0; }

    {{-- 「改善提案」ページ。 --}}
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    .rlead { width: 265mm; font-size: 10pt; color: #6B6767; margin: 0 0 4mm; line-height: 1.6; }
    .onepoint { width: 265mm; border-left: 4px solid #1D2088; background: #F5F5F5; padding: 3mm 4mm; margin: 0 0 5mm; }
    .onepoint .t { font-size: 10.5pt; font-weight: bold; margin: 0 0 1.5mm; }
    .onepoint p { font-size: 10pt; line-height: 1.7; margin: 0; }
    .gapbar td { padding: 0 0 2mm; font-size: 9.5pt; vertical-align: middle; }
    .gapbar .nm { width: 34mm; }
    .gapbar .bar { height: 6mm; display: block; }
    .gapbar .v { width: 26mm; text-align: right; color: #6B6767; padding-right: 3mm; }
    {{--
        2026-08-09: height:56mm固定をやめてauto(padding+内容まかせ)にした。
        比較サイトの実際の引用(competitor_evidence)が長い場合に、固定高さの
        箱からテキストがはみ出し、直後の「なお、これらを…」の行と重なる
        不具合が実PDF確認で見つかった(ユーザー指摘)。3枚は同じ<tr>内の
        セルのため、いずれか1枚が伸びれば残り2枚も同じ高さに揃う(通常の
        テーブル行の挙動)。あわせてBrandWheelImprovementFocusComposer側で
        引用に文字数上限を設け、極端に長い引用で改善提案ページ全体が
        7ページ枠を超えることも防ぐ。
    --}}
    .rcard { border: 1px solid #E0E0E0; padding: 3mm 3.5mm; }
    .rcard .no { font-size: 9pt; color: #fff; background: #1D2088; padding: 0.6mm 2.2mm; }
    .rcard .nm { font-size: 12pt; font-weight: bold; margin: 2mm 0 1.5mm; }
    .rcard .q { font-size: 9.5pt; color: #6B6767; margin: 0 0 3mm; line-height: 1.55; }
    .rcard .lb { font-size: 8.5pt; color: #8A8A8A; margin: 0 0 0.8mm; }
    .rcard .own { font-size: 9.5pt; margin: 0 0 3mm; }
    .rcard .cmp { font-size: 9.5pt; line-height: 1.6; margin: 0; border-left: 3px solid #E95446; padding-left: 2.5mm; }
    .rcell { width: 88.3mm; padding: 0 2mm 4mm 0; vertical-align: top; }

    {{--
        7ページ目(最終ページ)。2026-08-08: 旧3ブロック構成(.ctabox/.ctacell)は
        新文言(見出し+本文2段落のシンプルな構成)への差し替えに伴い削除した。
        2026-08-10: 連絡先確定に伴い.ctafoot(担当営業までご連絡くださいの
        仮文言)を.ctacontact(罫線ボックスでURLを大きく提示)に差し替え。
        あわせて.ctawrapにpadding-topを追加し、下半分が空白でバランスが
        悪かった問題に対応(ボックスを大きく・全体をやや下寄りにして
        ページ内の重心を中央付近に近づけた)。
    --}}
    .ctawrap { max-width: 250mm; margin: 0 auto; padding-top: 16mm; }
    .ctalogo { display: block; margin: 0 auto 8mm; width: 56mm; }
    .ctah { font-size: 17pt; font-weight: bold; text-align: center; margin: 0 0 6mm; line-height: 1.5; }
    .ctasub { font-size: 11pt; color: #393636; text-align: center; margin: 0 0 4mm; line-height: 1.9; }
    .ctacontact { width: 200mm; margin: 12mm auto 0; border: 1px solid #E0E0E0; border-left: 5px solid #1D2088; background: #F5F5F5; padding: 9mm 12mm; text-align: center; }
    .ctacontact .t { font-size: 12pt; font-weight: bold; margin: 0 0 3.5mm; color: #393636; }
    .ctacontact .url { font-size: 16pt; font-weight: bold; margin: 0 0 4.5mm; }
    .ctacontact .url a { color: #1D2088; text-decoration: none; }
    .ctacontact .note { font-size: 9.5pt; color: #6B6767; margin: 0; line-height: 1.7; }

    {{-- ロゴ(コーポレートサイト、512x94)。 --}}
    .logo-cover { display: block; margin: 0 auto 10mm; width: 72mm; }
    {{--
        2026-08-04: right:16mmで指定すると、ロゴ画像が右端で「LE」までしか
        描画されず途中で切れる不具合が実PDF確認で見つかった(.footのテキスト
        折り返し不具合とは別に、dompdfのposition:absoluteでは`right`指定自体が
        信頼できないことが判明)。leftへ変更して回避する。
        left = ページ幅297mm - 右余白16mm - ロゴ幅30mm = 251mm。

        2026-08-04追記: <img class="logo-mark">はHTML中の記述位置を、各
        .page内でh2の直後(=先頭寄り)に置くこと。末尾に置いていた旧実装では、
        実データでそのページの内容が2物理ページへあふれた際にロゴが
        丸ごと後ろ側(2枚目)へ付いていき、1枚目にロゴが無いページが生まれる
        不具合が実PDF確認で見つかった(position:absoluteの基準は.page要素
        自体だが、dompdfは.pageが物理ページをまたいで分割された場合、
        絶対配置要素をHTML中の出現順に基づいて「その時点で処理している
        物理ページ」に固定するらしい)。h2直後(あふれが起きる本文より前)に
        置くことで、常に1枚目の物理ページに固定されることを実PDF確認済み。
    --}}
    .logo-mark { position: absolute; left: 251mm; bottom: 7mm; width: 30mm; opacity: .9; }
</style>
</head>
<body>

@php
    $selfWheel = $viewModel->brandWheelSelf;
    $competitorWheel = $viewModel->brandWheelCompetitor;
    $selfReadable = ($selfWheel['status'] ?? null) === 'success' && ! empty($selfWheel['axes']);
    $competitorReadable = ($competitorWheel['status'] ?? null) === 'success' && ! empty($competitorWheel['axes']);
    $comparison = $viewModel->brandWheelComparison;

    // 2026-08-04: グループ名から色名(青/緑/赤)を外している ――
    // 配色をレジェンダに合わせた結果、緑が青緑に変わり色名と実際の色が
    // 食い違うため(docs/lead-report-layout/README.md参照)。
    $groupBands = [
        'company_appeal' => ['label' => '会社の魅力', 'color' => '#1D2088', 'tint' => '#D3D4EC'],
        'company_distance' => ['label' => '会社との距離', 'color' => '#2C7F96', 'tint' => '#CFE3EA'],
        'job_appeal' => ['label' => '仕事の魅力', 'color' => '#C03A28', 'tint' => '#F7DCD7'],
    ];
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
    ない)を、結果を見せる前にここで明示する。この一文は短縮・削除しない
    (2026-08-04: 引用符を『』に修正。ユーザー指定の「絶対に消してはいけない
    文言」原文どおり)。
--}}
<div class="page">
    <h2>採用ブランドの捉え方 ―― ブランド・ホイール</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
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
            <p class="introcaution">読み取れなかった項目は、その魅力が『無い』という意味ではありません。サイトにそう書かれていない、というだけです。また、採用ブランドは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNSなども併せて構築するものです。今回はそのうちサイトの記述のみを拝見しています。</p>
        </td>
    </tr></table>
</div>

{{--
    3. 自社サイトの分析結果／4. 競合サイトの分析結果。
    2026-08-08: 旧3ページ目(自社×競合を1ページに同居させていた版)から
    競合要素を分離した(README「7ページ構成」への再編、ユーザー指示)。
    partials/lead-pdf-brand-wheel-page.blade.phpを主体を変えて2回includeする
    ―― 完全に同じ形式・同じレイアウトにするため(コピー&改変ではなく
    共有パーシャルにすることで、将来のレイアウト変更が2箇所ズレる事故を防ぐ)。
    競合が無い診断では4ページ目自体を出さない。
--}}
@include('reports.partials.lead-pdf-brand-wheel-page', [
    'pageTitle' => '自社サイトの分析結果',
    'wheel' => $selfWheel,
    'readable' => $selfReadable,
    'seriesLabel' => '自社サイト',
    'seriesColor' => '#3A3FC0',
    'radarPng' => $viewModel->brandWheelRadarPngSelf,
    'summaryPoints' => $comparison['self_points'],
    'totalMatched' => $viewModel->selfTotalMatched,
    'totalMax' => $viewModel->selfTotalMax,
])

@if ($viewModel->competitorWebsiteUrl)
    @include('reports.partials.lead-pdf-brand-wheel-page', [
        'pageTitle' => '競合サイトの分析結果',
        'wheel' => $competitorWheel,
        'readable' => $competitorReadable,
        'seriesLabel' => '競合サイト',
        'seriesColor' => '#E95446',
        'radarPng' => $viewModel->brandWheelRadarPngCompetitor,
        'summaryPoints' => $comparison['competitor_points'],
        'totalMatched' => $viewModel->competitorTotalMatched,
        'totalMax' => $viewModel->competitorTotalMax,
    ])
@endif

{{--
    5. ○△－の対比表。●／－の2値から○△－の3値へ変更(2026-08-08)。
    ○△－の判定はすべてBrandWheelSubElementComparisonComposer(プログラム側)
    が行う ―― AIに3段階を判定させない(「AIの引用を原文照合で検証する」
    仕組みの外側に検証できない判断を入れないため、ユーザー指定)。
    ○×は使わない(×は正解・不正解の記号であり、2ページ目の「読み取れな
    かった項目は魅力が無いという意味ではない」という断りと矛盾する)。
    合計(○の件数)は$viewModel->selfTotalMatched等(3・4ページ目と同じ
    集計値)を使う ―― ページごとに個別集計しない。△は合計に含めず、
    「(参考)」として別行に出す。
--}}
@php
    $mark = function (string $state, bool $competitor = false) {
        return match ($state) {
            'matched' => '<span class="mkon'.($competitor ? ' cp' : '').'">○</span>',
            'label_only' => '<span class="mktri">△</span>',
            default => '<span class="mkoff">－</span>',
        };
    };
@endphp
<div class="page">
    <h2>○△－の対比表</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

    @if (! $selfReadable)
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @else
        @php
            $comparisonByGroup = collect($viewModel->subElementComparison)->groupBy('group');
            $showCompetitorColumn = $viewModel->competitorWebsiteUrl !== null;
        @endphp
        <p class="vslead">24項目それぞれについて、サイトに該当する記述があったかどうかを3段階で示しています。凡例は下記のとおりです。</p>

        {{--
            ○△－凡例と自社×競合を重ねたレーダー図。3・4ページを自社単独・
            競合単独に分けたことで、視覚的な対比はこのページにしか無い
            (README方針)。競合が読み取れない場合はレーダーだけ出さない
            (3・4ページと同じ方針、凡例は競合の有無にかかわらず必要なため
            常に出す)。
            2026-08-10: レーダーと○△－凡例を縦2段(レーダー行→凡例行)で
            並べていたところ、3グループ表(8行×3列、常に固定の高さ)と
            合わせて190mm上限の残り8.3mmしか余白が無い状態になった
            (ユーザー指摘: 10mm未満は不合格)。3・4ページと同じ考え方で、
            横並びに変更 ―― 凡例の高さ(3行)はレーダーの高さ以下のため、
            この行の高さはレーダーだけで決まり、凡例ぶんの追加コストが
            無くなる。あわせてレーダーも46x33.5mm→68x49.4mm(縦横比380:276を
            維持)に拡大した。

            **列の並び順(凡例を左・レーダーを右)を変えないこと。** 逆
            (レーダーを左・幅の広い凡例のtdを右=表の最終列)にすると、
            table-layout:fixedで列幅を指定していても最終列がページ右端
            (297mm)を大きく超えて描画される不具合をdompdfで実PDF確認した
            (2026-08-10)。原因は特定できていないが、「幅の広い折り返しテキスト
            を持つtdを固定テーブルの最終列に置かない」という回避策で解消した。
        --}}
        <table style="width: 265mm; table-layout: fixed;"><tr>
            <td style="width: 165mm; vertical-align: top;">
                <div class="cmplegend">
                    <p><span class="mk mkon">○</span> 本文の記述から確認できた項目</p>
                    <p><span class="mk mktri">△</span> 見出し・メニュー名などのラベルのみで、本文からは確認できなかった項目</p>
                    <p><span class="mk mkoff">－</span> 該当する記述が見つからなかった項目(『魅力が無い』という意味ではありません)</p>
                </div>
            </td>
            <td style="width: 8mm;"></td>
            <td style="width: 92mm; text-align: center; vertical-align: top;">
                @if ($competitorReadable && $viewModel->brandWheelRadarPngComparison)
                    <img src="data:image/png;base64,{{ base64_encode($viewModel->brandWheelRadarPngComparison) }}" style="width: 68mm; height: 49.4mm;">
                    <div class="legend">
                        <span class="sw" style="background: #3A3FC0;"></span>自社サイト
                        <span class="sw" style="background: #E95446;"></span>競合サイト
                    </div>
                @endif
            </td>
        </tr></table>

        {{-- 外側のテーブル(vscell、3列とも88.3mmで等しい)はtable-layout:auto
             にしない(fixedのままで安全、ページ3のaxcellと同じパターン)。
             内側のvstbl(sub/自社/比較の3列、幅が不均等)はtable-layout:auto
             にする(2026-08-04、CSS側コメント参照) ―― この列の内容は
             config('brand_wheel.axes.*.sub_elements')のラベル(数文字)と
             ○△－の1文字のみで、長文が入ることは無いため、autoにしても
             ページ右端をはみ出すリスクが無いことを確認済み。 --}}
        <table style="width: 265mm;"><tr>
            @foreach ($groupBands as $groupKey => $band)
                <td class="vscell">
                    <div class="grpbar" style="background: {{ $band['color'] }};">{{ $band['label'] }}</div>
                    <table class="vstbl" style="table-layout: auto;"><tr>
                        <th class="sub"></th>
                        <th>自社</th>
                        @if ($showCompetitorColumn)
                            <th>比較</th>
                        @endif
                    </tr>
                    @foreach ($comparisonByGroup->get($groupKey, []) as $item)
                        <tr>
                            <td class="sub">{{ $item['sub_name'] }}</td>
                            <td class="mk">{!! $mark($item['self_state']) !!}</td>
                            @if ($showCompetitorColumn)
                                <td class="mk">{!! $mark($item['competitor_state'], true) !!}</td>
                            @endif
                        </tr>
                    @endforeach
                    </table>
                </td>
            @endforeach
        </tr></table>

        <p class="vslegend">
            合計　<span class="mkon">○</span> 自社サイト {{ $viewModel->selfTotalMatched }} / {{ $viewModel->selfTotalMax }}項目
            @if ($showCompetitorColumn)
                　　<span class="mkon cp">○</span> 比較サイト {{ $viewModel->competitorTotalMatched }} / {{ $viewModel->competitorTotalMax }}項目
            @endif
        </p>
        <p class="vsreflegend">
            (参考)　<span class="mktri">△</span> 自社 {{ $viewModel->selfTotalLabelOnly }}件
            @if ($showCompetitorColumn)
                　　<span class="mktri">△</span> 比較 {{ $viewModel->competitorTotalLabelOnly }}件
            @endif
        </p>
    @endif
</div>

{{--
    6. 改善提案。ブランド・ホイール起点(README「技術的な指標から作らない
    こと」)。ワンポイントは自社のみで判定可能なため常に自社の状態から出す。
    領域差・3項目は$viewModel->improvementFocus(BrandWheelImprovementFocusComposer、
    決定的な規則で選定)が唯一の情報源。△は未該当扱いのまま(選定ロジックは
    無改修 ―― 自社△かつ競合○の項目も引き続き候補に含まれる、ユーザー指定)。

    2026-08-08: 下部の技術的提案ブロック(「あわせて、サイトの作りに
    ついて」)を削除した。4観点(測定結果)ページを削除したのに技術的提案
    だけ残すのは整合が取れないため(ユーザー判断)。
--}}
<div class="page">
    <h2>改善提案</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

    @if (! $selfReadable)
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @else
        @if ($comparison['one_point'])
            <div class="onepoint">
                <p class="t">【ワンポイント】</p>
                <p>{{ $comparison['one_point']['text'] }}</p>
            </div>
        @endif

        @if ($viewModel->improvementFocus)
            @php
                $focus = $viewModel->improvementFocus;
                $selectedLabel = $groupBands[$focus['selected_group']]['label'] ?? $focus['selected_group'];
            @endphp
            {{--
                2026-08-04: 文言修正。旧文言「候補者が比較サイト側でしか情報を
                得られない差が最も大きかったのは」は、選定ロジック(競合の
                該当件数－自社の該当件数がグループ内で最大)と食い違って見える
                ―― 自社が競合を上回るグループが選ばれることがあり(実データで
                確認: 「会社の魅力」は自社2件・比較1件で自社が多いにも
                関わらず選ばれた。全グループで自社優位のとき、選ばれるのは
                「自社の優位が最も小さい(＝競合との差が最も小さい)グループ」
                であって「競合が上回るグループ」ではないため)、直下の
                件数バー(自社が多い)と文言(競合の方が情報が多いと読める)が
                矛盾して見える不具合が実PDF確認で見つかった。
                「差(比較サイト件数－自社件数)」を明示し、数値で検算できる
                言い回しに変更する。件数バー自体はREADME「グループごとの
                自社／競合件数バーを出し、差が最大の領域を特定する」の指定
                通り残す(バーを別指標に変える案は取らない)。
            --}}
            <p class="rlead">3つの領域のうち、比較サイトとの差(比較サイト件数－自社件数)が最も大きかったのは「{{ $selectedLabel }}」でした。この領域から、比較サイトの記述にあり御社のサイトには無い項目を{{ count($focus['items']) }}件挙げます。</p>

            {{-- 2026-08-04: table-layout:autoにする理由はページ3のstatrowと
                 同じ(CSS側コメント参照)。この表は5列(nm/v/bar/v/bar)が
                 不均等なため、fixedのままだと均等割りされて棒グラフの幅が
                 崩れる。widthも明示する(h2/.darkbandと同じ理由 ―― `.page`
                 直下でwidth未指定だと右に16mmはみ出す)。 --}}
            <table class="gapbar" style="width: 265mm; table-layout: auto;">
                @foreach ($focus['groups'] as $group)
                    @php
                        $label = $groupBands[$group['group']]['label'] ?? $group['group'];
                        $selfRatio = $group['max_count'] > 0 ? $group['self_count'] / $group['max_count'] * 100 : 0;
                        $competitorRatio = $group['max_count'] > 0 ? $group['competitor_count'] / $group['max_count'] * 100 : 0;
                    @endphp
                    <tr>
                        <td class="nm">{{ $label }}</td>
                        <td class="v">自社 {{ $group['self_count'] }} / {{ $group['max_count'] }}</td>
                        <td style="width: 52mm;"><span class="bar" style="background: #3A3FC0; width: {{ number_format($selfRatio, 1) }}%;"></span></td>
                        <td class="v">比較 {{ $group['competitor_count'] }} / {{ $group['max_count'] }}</td>
                        <td style="width: 52mm;"><span class="bar" style="background: #E95446; width: {{ number_format($competitorRatio, 1) }}%;"></span></td>
                    </tr>
                @endforeach
            </table>

            @if (count($focus['items']) === 0)
                <p class="gnone" style="margin-top: 3mm;">該当する項目はありませんでした</p>
            @else
                {{-- table-layout:autoにしない理由 ―― この列にはcompetitor_evidence
                     (比較サイトの実際の抜粋、長文になりうる)が入るため、autoにすると
                     列幅がページ右端を超える危険がある。列幅は全列88.3mmで
                     等しいので、fixedのままで安全に収まることを確認済み。 --}}
                <table style="width: 265mm; margin-top: 3mm;">
                    <tr>
                        @foreach ($focus['items'] as $i => $item)
                            <td class="rcell">
                                <div class="rcard">
                                    <span class="no">{{ $i + 1 }}</span>
                                    <p class="nm">{{ $item['sub_name'] }}</p>
                                    <p class="q">{{ $item['definition'] }}</p>
                                    <p class="lb">御社のサイト</p>
                                    <p class="own">記述が見つかりませんでした</p>
                                    <p class="lb">比較サイトの記述</p>
                                    <p class="cmp">「{{ $item['competitor_evidence'] }}」</p>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </table>

                <p class="rlead" style="margin: 3mm 0 0;">なお、これらを『サイトに書き足す』ことで解決するとは限りません。実態はあるのに伝えられていないのか、まだ言葉になっていないのか ―― その切り分けについては最終ページをご覧ください。</p>
            @endif
        @elseif ($comparison['one_point'])
            <p class="rlead">比較サイトが無いため、領域ごとの比較はご用意できません。</p>
        @endif
    @endif
</div>

{{--
    7. サイトの改善をすれば課題が解決するとは限りません(最終ページ)。
    2026-08-08: 旧「ここから先は、サイトの外の話です」(3ブロック構成)を
    ユーザー指定の新文言に全面差し替え。一見してメッセージが分かるよう、
    見出し+本文2段落のシンプルな構成にする(3ブロックのボックス構成は廃止)。
    旧CTA「他社比較(3〜5社)」は使わない、というdocs/lead-report-layout/
    README.mdの記述はこの新文言と矛盾しないためREADME側を更新した
    (前段でタッチポイント全体の設計に言及した上で、比較を議論の入口として
    提示しているため)。

    2026-08-10: 連絡先が確定(ユーザー指示)。掲載するのは
    https://www.leggenda.co.jp/contact/ (公式サイトの問い合わせページ)の
    みで、外部フォームツール本体のURLは掲載しない ―― 印刷物に自社ドメイン
    以外のURLが出るとフィッシングを疑われる・フォームツールを乗り換えた
    際にPDFの刷り直しが必要になるため。電話番号は掲載しない(問い合わせ
    ページに「現在、お電話の受付を停止しております」と明記されており、
    受付時間の記載も無いため)。汎用の問い合わせ窓口経由では診断レポート
    起点の問い合わせだと営業側で判別できないため、発行日・貴社名を伝える
    よう促す一文を添える。発行日は表紙と同じ$viewModel->generatedAtLabelを
    参照し、二重管理しない。
--}}
<div class="page cta">
    <div class="ctawrap">
        <img class="ctalogo" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
        <p class="ctah">サイトの改善をすれば<br>課題が解決するとは限りません</p>
        <p class="ctasub">サイトの改善が最も効果的な打ち手となるのか、応募から内定までの間での候補者とのタッチポイント全体の設計を改めて行うことで大きな効果を得られるのかを見直す必要があります。</p>
        <p class="ctasub">弊社にてさらに幅を広げ、3〜5社の競合他社のサイトと比較した結果をもとにどこに御社課題があるかをディスカッションしませんか。ご希望いただける場合は、以下よりご連絡ください。</p>

        <div class="ctacontact">
            <p class="t">ご相談・お問い合わせ</p>
            <p class="url"><a href="https://www.leggenda.co.jp/contact/">https://www.leggenda.co.jp/contact/</a></p>
            <p class="note">お問い合わせの際は、本レポートの発行日（{{ $viewModel->generatedAtLabel }}）と貴社名をお知らせください。</p>
        </div>
    </div>
</div>

</body>
</html>
