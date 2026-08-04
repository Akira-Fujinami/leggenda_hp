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
    .page { width: 297mm; padding: 14mm 16mm 20mm; position: relative; page-break-after: always; }
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
    h2 { width: 265mm; font-size: 15pt; margin: 0 0 5mm; font-weight: normal; border-bottom: 1px solid #E0E0E0; padding-bottom: 2mm; }
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
    .lead1 { width: 265mm; font-size: 10pt; color: #6B6767; margin: 0 0 2mm; line-height: 1.4; }
    .sumhead { width: 265mm; font-size: 10.5pt; font-weight: bold; margin: 0 0 1.5mm; }
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
    {{-- widthを明示する理由はh2と同じ(2026-08-04、上記コメント参照)。 --}}
    .darkband { width: 265mm; background: #1D2088; color: #fff; padding: 2mm 5mm; margin-top: 2mm; }
    .darkband p { margin: 0.5mm 0; font-size: 10pt; line-height: 1.6; }
    .arrow { text-align: center; font-size: 20pt; color: #1D2088; padding: 3mm 0; }
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

    {{-- 「サイトから読み取れた記述」ページ(evidence一覧)。 --}}
    .evtbl { border-collapse: collapse; width: 100%; }
    .evtbl th { background: #F5F5F5; border: 1px solid #E0E0E0; font-size: 10pt; font-weight: bold; text-align: left; padding: 2.5mm 3mm; }
    .evtbl td { border: 1px solid #E0E0E0; padding: 2.5mm 3mm; font-size: 10pt; line-height: 1.7; vertical-align: top; }
    .evaxis { width: 34mm; font-weight: bold; }
    .evsub { width: 42mm; color: #393636; }
    .evq { width: 189mm; color: #393636; }
    .evbar { display: inline-block; width: 4px; height: 11px; margin-right: 5px; vertical-align: -1px; }

    {{-- 「24項目の対比」ページ。 --}}
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    .vslead { width: 265mm; font-size: 10pt; color: #6B6767; margin: 0 0 4mm; line-height: 1.6; }
    .vscell { width: 88.3mm; padding: 0 2mm 4mm 0; vertical-align: top; }
    .grpbar { color: #fff; font-size: 10.5pt; font-weight: bold; text-align: center; padding: 1.8mm; }
    .vstbl th { font-size: 9.5pt; font-weight: bold; padding: 2mm 1mm; border-bottom: 1px solid #E0E0E0; color: #6B6767; text-align: center; }
    .vstbl th.sub { text-align: left; }
    .vstbl td { font-size: 9.5pt; padding: 1.9mm 1mm; border-bottom: 1px solid #EFEFEF; }
    .vstbl td.sub { text-align: left; }
    .vstbl td.mk { text-align: center; width: 13mm; font-size: 11pt; }
    .mkon { color: #1D2088; font-weight: bold; }
    .mkon.cp { color: #E95446; }
    .mkoff { color: #BFBFBF; }
    .vslegend { width: 265mm; font-size: 9.5pt; color: #6B6767; margin: 4mm 0 0; }

    {{--
        「サイトで触れられていなかった項目」ページ。
        2026-08-04: draft.htmlは前置きページの.gcell(34mm、色見本セル)と
        このページの.gcell(33.3%、カードセル)を同じクラス名で2回定義しており、
        CSSの後勝ちルールにより前置きページ側の意図しない上書きを招く
        (draft.html自体の記述ミス)。移植時は衝突を避けるため
        別名(.gapcell)にする。
    --}}
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    .gaplead { width: 265mm; font-size: 10pt; color: #6B6767; margin: 0 0 4mm; line-height: 1.6; }
    .gaphead { width: 265mm; font-size: 11.5pt; font-weight: bold; margin: 0 0 2.5mm; }
    .gaphead .cnt { font-size: 9.5pt; font-weight: normal; color: #6B6767; margin-left: 3mm; }
    .gcard { border: 1px solid #E0E0E0; border-left: 3px solid #C03A28; padding: 2.5mm 3mm; height: 19mm; }
    .gcard.own { border-left-color: #1D2088; }
    .gcard .nm { font-size: 10.5pt; font-weight: bold; margin: 0 0 1.5mm; }
    .gcard .ds { font-size: 9pt; color: #6B6767; margin: 0; line-height: 1.55; }
    .gapcell { width: 88.3mm; padding: 0 2mm 3mm 0; vertical-align: top; }
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント
         参照)。実PDF確認で、C分類(どちらにも無い項目)の一覧テキストが
         ページ右端をはみ出していることが見つかった。 --}}
    .gnone { width: 265mm; border: 1px solid #E0E0E0; background: #F5F5F5; padding: 2.5mm 3mm; font-size: 9.5pt; color: #6B6767; line-height: 1.7; margin: 0; }

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
    .rcard { border: 1px solid #E0E0E0; padding: 3mm 3.5mm; height: 56mm; }
    .rcard .no { font-size: 9pt; color: #fff; background: #1D2088; padding: 0.6mm 2.2mm; }
    .rcard .nm { font-size: 12pt; font-weight: bold; margin: 2mm 0 1.5mm; }
    .rcard .q { font-size: 9.5pt; color: #6B6767; margin: 0 0 3mm; line-height: 1.55; }
    .rcard .lb { font-size: 8.5pt; color: #8A8A8A; margin: 0 0 0.8mm; }
    .rcard .own { font-size: 9.5pt; margin: 0 0 3mm; }
    .rcard .cmp { font-size: 9.5pt; line-height: 1.6; margin: 0; border-left: 3px solid #E95446; padding-left: 2.5mm; }
    .rcell { width: 88.3mm; padding: 0 2mm 4mm 0; vertical-align: top; }
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    {{-- 2026-08-04: margin-top 5mm→3mm、padding-top 3.5mm→2mmに縮小。
         このブロック単体の高さは十分な余白(37.4mm)に収まるはずなのに次
         ページへ丸ごと送られる不具合が実PDF確認で見つかった(README
         「技術的提案は改善提案ページ下部に小さく残す」を満たすため、
         余白を削って収まりやすくする)。 --}}
    .tech { width: 265mm; border-top: 1px solid #E0E0E0; margin-top: 3mm; padding-top: 2mm; }
    .tech .h { font-size: 10pt; font-weight: bold; margin: 0 0 2mm; }
    .tech p { font-size: 9.5pt; color: #6B6767; margin: 0; line-height: 1.7; }

    {{-- 「ここから先は、サイトの外の話です」(9ページ目、最終ページ)。 --}}
    .ctawrap { max-width: 250mm; margin: 0 auto; }
    .ctalogo { display: block; margin: 0 auto 8mm; width: 56mm; }
    .ctah { font-size: 17pt; font-weight: bold; text-align: center; margin: 0 0 3mm; }
    .ctasub { font-size: 10.5pt; color: #6B6767; text-align: center; margin: 0 0 8mm; line-height: 1.8; }
    .ctabox { border: 1px solid #E0E0E0; padding: 4mm 5mm; height: 58mm; }
    .ctabox .n { font-size: 9pt; color: #fff; background: #1D2088; padding: 0.6mm 2.2mm; }
    .ctabox .t { font-size: 11.5pt; font-weight: bold; margin: 2.5mm 0 2mm; }
    .ctabox p { font-size: 9.5pt; line-height: 1.75; margin: 0; color: #393636; }
    {{-- 2026-08-04: 83.3mm×3=249.9mm(.ctawrapのmax-width:250mmに合わせる。
         .pageの265mmではない ―― このテーブルだけ.ctawrap配下のため)。 --}}
    .ctacell { width: 83.3mm; padding: 0 2mm 0 0; vertical-align: top; }
    .ctafoot { text-align: center; margin-top: 8mm; }
    .ctafoot .l1 { font-size: 11.5pt; margin: 0 0 2mm; }
    .ctafoot .l2 { font-size: 10pt; color: #6B6767; margin: 0; line-height: 1.8; }

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

    $badgeClassByStatus = [
        'good' => 'good',
        'needs_review' => 'review',
        'needs_improvement' => 'warn',
        'not_measured' => 'neutral',
        'not_applicable' => 'neutral',
        'not_detected' => 'neutral',
        'unavailable' => 'neutral',
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

{{-- 3. 自社ページの分析結果 --}}
<div class="page">
    <h2>自社ページの分析結果</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

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
        {{--
            2026-08-04: table-layout:autoにする ―― 1行に3列以上・かつ列ごとに
            違う幅を指定したtableで、dompdfがtable-layout:fixed下では宣言した
            各列幅を無視し、テーブル全体の幅を列数で均等割りしてしまう不具合が
            実PDF確認で見つかった(この行は6列: 50/4/50/4/77/80mmのはずが
            全列およそ44mmの均等割りになり、レーダー画像がページ右端を
            大きくはみ出して見切れていた)。列幅が全列同じ場合は均等割りの
            結果と一致するため症状が出ない(このファイル内の他の3列以上の
            テーブルも同じ理由でtable-layout:autoにしている)。table-layout:auto
            でも画像(このtd内のレーダー画像)を含む列の幅が意図通り(76mm)に
            保たれることは実PDF確認済み。
        --}}
        <table class="statrow" style="width: 265mm; table-layout: auto;"><tr>
            <td style="width: 50mm;">
                <div class="statbox">
                    <p class="lab"><span class="swatch" style="background: #3A3FC0;"></span>自社サイト</p>
                    <p class="num">{{ $viewModel->selfTotalMatched }}<small> / {{ $viewModel->selfTotalMax }}項目</small></p>
                </div>
            </td>
            <td style="width: 4mm;"></td>
            @if ($competitorReadable)
                <td style="width: 50mm;">
                    <div class="statbox">
                        <p class="lab"><span class="swatch" style="background: #E95446;"></span>競合サイト</p>
                        <p class="num">{{ $viewModel->competitorTotalMatched }}<small> / {{ $viewModel->competitorTotalMax }}項目</small></p>
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
                    {{--
                        2026-08-04: 76x55mm→66x48mm(縦横比380:276を維持)に
                        縮小。README「合計件数Nは同じソースから」等とは無関係の
                        純粋なレイアウト都合 ―― このレーダーtdの高さがstatrow行
                        全体の高さを決めるため、実データ(自社側5件)で
                        統計ボックス下に約40mmの余白が生まれ、軸カード表が
                        次ページへあふれる一因になっていた。サマリー箇条書きは
                        可変長(0〜6件超)のため、この行と同じ行には置けない
                        (2026-08-04より前の実PDF確認で、そうすると行全体の
                        高さが不安定になり同じくあふれる不具合が別途見つかって
                        いる) ―― そのため縮小できるのはレーダー画像のみ。
                    --}}
                    <img src="data:image/png;base64,{{ base64_encode($viewModel->brandWheelRadarPng) }}" style="width: 66mm; height: 48mm;">
                    <div class="legend">
                        <span class="sw" style="background: #3A3FC0;"></span>自社サイト
                        @if ($competitorReadable)
                            <span class="sw" style="background: #E95446;"></span>競合サイト
                        @endif
                    </div>
                @endif
            </td>
        </tr></table>

        <p class="sumhead" style="margin-top: 2mm;">サマリー</p>
        <ul class="sum">
            @foreach ($comparison['self_points'] as $point)
                <li>{{ $point }}</li>
            @endforeach
        </ul>

        {{-- widthを明示する理由はh2/.darkbandと同じ(2026-08-04、CSS側コメント参照)。 --}}
        <table class="bandrow" style="width: 265mm; margin-top: 2mm;"><tr>
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
            {{-- 2026-08-04: 旧「他社ページ比較とのまとめ」ページ(削除済み)の
                 footer disclaimerを移設。key_message/impressionがAI生成である
                 ことの開示は、削除に伴って失われてはならない誠実性表示のため。 --}}
            <p class="foot" style="margin-top: 2mm;">キーメッセージと印象の読み取りにはAIを使用しています。</p>
        @endif
    @endif
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
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
    <p class="lead1">前ページで「該当あり」とした項目について、サイトのどの記述を根拠にしたかを記載しています。抜粋はサイト上の文章をそのまま引用したもので、要約や言い換えは含みません。</p>
    {{--
        2026-08-04: table-layout:fixedのままにする(autoにしない)。
        このテーブルは3列(34/42/189mm)が不均等で、fixed下では宣言した
        列幅が無視されて3等分(約88mmずつ)される不具合を実PDF確認で発見した
        ―― 「項目」「何について」列が意図より広く、「サイトからの記述」列が
        意図(189mm)より狭く(約88mm)なる。ただしこれはページ右端をはみ出す
        崩れ方ではなく、evidence(長文になりうる)の折り返し行数が増えるだけ
        (word-wrap:break-wordで折り返される)。autoに変更するとdefinition同様、
        evidenceの長さ次第でページ右端をはみ出す危険の方が大きいため、安全な
        fixed(3等分)側を選んでいる。この列幅の不均等さは既知の見た目の課題
        として残す(オーバーフローではない)。
    --}}
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
</div>
@endif

{{-- 5. 採用担当の視点で見た診断結果(4観点。判定と理由1文のみ、個別指標名は出さない) --}}
<div class="page">
    <h2>採用担当の視点で見た診断結果</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
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
</div>

{{--
    6. 24項目の対比。●(記述あり)／－(記述が見つからなかった)で示す。
    ○×は使わない(正解・不正解の記号であり、×が並ぶと採点で落ちたように
    見える。2ページ目の断り書きと矛盾する、docs/lead-report-layout/README.md)。
    24項目はconfig('brand_wheel.axes')の並び順(=$viewModel->subElementComparison
    の順、BrandWheelSubElementComparisonComposerが唯一の情報源)のまま出す。
    合計は$viewModel->selfTotalMatched等(自社ページの分析結果ページと同じ
    集計値)を使う ―― ページごとに個別集計しない。
--}}
<div class="page">
    <h2>24項目の対比</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

    @if (! $selfReadable)
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @else
        @php
            $comparisonByGroup = collect($viewModel->subElementComparison)->groupBy('group');
            $showCompetitorColumn = $viewModel->competitorWebsiteUrl !== null;
        @endphp
        <p class="vslead">24項目それぞれについて、サイトに該当する記述があったかどうかを並べています。●は記述があったこと、－は記述が見つからなかったことを示します。－は『その魅力が無い』という意味ではなく、そのサイトでは触れられていなかった、という意味です。</p>

        {{-- 外側のテーブル(vscell、3列とも88.3mmで等しい)はtable-layout:auto
             にしない(fixedのままで安全、ページ3のaxcellと同じパターン)。
             内側のvstbl(sub/自社/比較の3列、幅が不均等)はtable-layout:auto
             にする(2026-08-04、CSS側コメント参照) ―― この列の内容は
             config('brand_wheel.axes.*.sub_elements')のラベル(数文字)と
             ●／－の1文字のみで、長文が入ることは無いため、autoにしても
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
                            <td class="mk">
                                @if ($item['self_matched'])
                                    <span class="mkon">●</span>
                                @else
                                    <span class="mkoff">－</span>
                                @endif
                            </td>
                            @if ($showCompetitorColumn)
                                <td class="mk">
                                    @if ($item['competitor_matched'])
                                        <span class="mkon cp">●</span>
                                    @else
                                        <span class="mkoff">－</span>
                                    @endif
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </table>
                </td>
            @endforeach
        </tr></table>

        <p class="vslegend">
            合計　<span class="mkon">●</span> 自社サイト {{ $viewModel->selfTotalMatched }} / {{ $viewModel->selfTotalMax }}項目
            @if ($showCompetitorColumn)
                　　<span class="mkon cp">●</span> 比較サイト {{ $viewModel->competitorTotalMatched }} / {{ $viewModel->competitorTotalMax }}項目
            @endif
        </p>
    @endif
</div>

{{--
    7. サイトで触れられていなかった項目。自社と競合のmatchedを突き合わせた
    3分類(A: 比較サイトにあり自社に無い/B: 自社にあり比較サイトに無い/
    C: どちらにも無い)。$viewModel->gapAnalysisが唯一の情報源
    (BrandWheelSubElementComparisonComposer::splitByGap())。
    A+B+C+共通=24であることはBrandWheelSubElementComparisonComposerTestで
    検算済み。
--}}
<div class="page">
    <h2>サイトで触れられていなかった項目</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

    @if (! $selfReadable)
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @elseif (! $competitorReadable)
        <p>比較サイトが指定されていないため、この分析はご用意できませんでした。</p>
    @else
        @php $gap = $viewModel->gapAnalysis; @endphp
        <p class="gaplead">24項目のうち、比較サイトには記述があり、御社のサイトでは触れられていなかった項目です。書かれていないことが弱みという意味ではありません。候補者が2つのサイトを見比べたとき、その情報を比較サイト側でしか得られない、という事実を示しています。</p>

        <p class="gaphead">比較サイトにあり、御社のサイトでは触れられていなかった項目<span class="cnt">{{ count($gap['a']) }}件</span></p>
        @if (count($gap['a']) === 0)
            <p class="gnone">該当する項目はありませんでした</p>
        @else
            {{--
                table-layout:autoにはしない ―― 列幅が全列同じ(88.3mm)+
                table-layout:fixedの組み合わせは、内容が長文でも列幅がページ
                右端を超えて崩れないことを実PDF確認済み(ページ3のaxcellと
                同じパターン)。逆にautoにすると、definition(下位要素の
                1行定義文、長いもので40字超)がその列の幅を押し広げ、
                ページ右端をはみ出す不具合が実PDF確認で見つかった
                (2026-08-04、当初axcellにautoを試して発覚)。
                widthは265mmを明示する(h2/.darkbandと同じ理由 ―― `.page`
                直下でwidth未指定だと右に16mmはみ出す不具合が、このテーブル
                自体にも実PDF確認で見つかった。2026-08-04追加)。
            --}}
            <table style="width: 265mm;">
                @foreach (array_chunk($gap['a'], 3) as $row)
                    <tr>
                        @foreach ($row as $item)
                            <td class="gapcell"><div class="gcard"><p class="nm">{{ $item['sub_name'] }}</p><p class="ds">{{ $item['definition'] }}</p></div></td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif

        <p class="gaphead" style="margin-top: 3mm;">御社のサイトにあり、比較サイトでは触れられていなかった項目<span class="cnt">{{ count($gap['b']) }}件</span></p>
        @if (count($gap['b']) === 0)
            {{-- Bを省略しない(Aだけ並べると詰問状になる、
                 docs/lead-report-layout/README.md)。0件でも枠を出す。 --}}
            <p class="gnone">該当する項目はありませんでした</p>
        @else
            {{-- table-layout:autoにしない理由・widthを明示する理由は
                 上のAのテーブルと同じ。 --}}
            <table style="width: 265mm;">
                @foreach (array_chunk($gap['b'], 3) as $row)
                    <tr>
                        @foreach ($row as $item)
                            <td class="gapcell"><div class="gcard own"><p class="nm">{{ $item['sub_name'] }}</p><p class="ds">{{ $item['definition'] }}</p></div></td>
                        @endforeach
                    </tr>
                @endforeach
            </table>
        @endif

        <p class="gaphead" style="margin-top: 3mm;">どちらのサイトでも触れられていなかった項目<span class="cnt">{{ count($gap['c']) }}件</span></p>
        @if (count($gap['c']) === 0)
            <p class="gnone">該当する項目はありませんでした</p>
        @else
            <p class="gnone">{{ implode('　/　', array_column($gap['c'], 'sub_name')) }}</p>
        @endif
    @endif
</div>

{{--
    8. 改善提案。ブランド・ホイール起点(README「技術的な指標から作らない
    こと」)。ワンポイントは自社のみで判定可能なため常に自社の状態から出す。
    領域差・3項目は$viewModel->improvementFocus(BrandWheelImprovementFocusComposer、
    決定的な規則で選定)が唯一の情報源。技術的な提案(画像・速度・フォーム等)は
    下部に小さく残す(このページの主役は「何を書くか」であり、それとは別の
    話であることを明記する)。
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
                {{-- table-layout:autoにしない理由はページ7のgapcellと同じ
                     (2026-08-04) ―― この列にはcompetitor_evidence(比較サイトの
                     実際の抜粋、長文になりうる)が入るため、autoにすると
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

        @if (count($viewModel->topRecommendations) > 0)
            <div class="tech">
                <p class="h">あわせて、サイトの作りについて</p>
                <p>
                    {{ implode('／', array_map(fn ($r) => $r->title, $viewModel->topRecommendations)) }}
                    の{{ count($viewModel->topRecommendations) }}点に改善の余地がありました。
                    いずれもサイトの作りに関するもので、上の『何を書くか』とは別の話です。詳細は担当者よりご説明します。
                </p>
            </div>
        @endif
    @endif
</div>

{{--
    9. ここから先は、サイトの外の話です。旧CTA「他社比較(3〜5社)」は
    使わない(レジェンダは採用コンサルであり、サイト制作会社ではないため、
    docs/lead-report-layout/README.md)。2ページ目の「ブランド・ホイールは
    本来サイト以外の情報も併せて構築するもの」という断りをこのページで
    回収する構造(対応関係を崩さないこと)。

    3ブロック目の本文(社員・内定者・辞退者へのヒアリング等)はREADMEの
    注記どおり相談側の推測であり、実際のサービスメニューとの一致は
    未確認(2026-08-04、ユーザーへ確認済み: 確認が取れるまでdraft.htmlの
    文言のまま実装する暫定対応)。
--}}
<div class="page cta">
    <div class="ctawrap">
        <img class="ctalogo" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
        <p class="ctah">ここから先は、サイトの外の話です</p>
        <p class="ctasub">今回お見せしたのは、御社の採用サイトに「何が書かれているか」だけです。<br>これは採用ブランドを形づくる情報源のひとつにすぎません。</p>

        {{-- widthを明示する理由はh2/.darkbandと同じ(2026-08-04、CSS側コメント
             参照)。幅は.ctawrapのmax-width(250mm)に合わせる(265mmではない
             ―― このテーブルだけ.pageではなく.ctawrap配下のため)。
             table-layout:autoにはしない ―― 列幅が全列同じ(83.3mm)+fixedは
             安全(ページ3のaxcellで確認済みのパターン)。 --}}
        <table style="width: 250mm;"><tr>
            <td class="ctacell"><div class="ctabox">
                <span class="n">1</span>
                <p class="t">書かれていない項目には<br>2つの意味があります</p>
                <p>実態はあるのに伝えられていないのか、まだ言葉になっていないのか。前者はサイトで解決しますが、後者はサイトを直しても変わりません。</p>
            </div></td>
            <td class="ctacell"><div class="ctabox">
                <span class="n">2</span>
                <p class="t">その切り分けは<br>サイトからはできません</p>
                <p>社員が実際に何を感じているか、辞退した方が何を理由に離れたか、説明会で何を語っているか。サイト以外の情報と突き合わせて初めて判断できます。</p>
            </div></td>
            <td class="ctacell" style="padding-right: 0;"><div class="ctabox">
                <span class="n">3</span>
                <p class="t">私たちは採用ブランドの<br>設計からご一緒します</p>
                <p>グループインタビュー、社員・内定者・辞退者へのヒアリング、説明会の設計。何を約束する会社なのかを言葉にするところから支援しています。</p>
            </div></td>
        </tr></table>

        <div class="ctafoot">
            <p class="l1">この診断で見えた差が、伝え方の問題なのか、言語化の問題なのか。</p>
            <p class="l2">一度お話しさせてください。担当者よりご連絡いたします。</p>
        </div>
    </div>
</div>

</body>
</html>
