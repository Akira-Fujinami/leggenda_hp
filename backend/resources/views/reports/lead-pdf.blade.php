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
    {{--
        2026-08-17: widthを明示する理由はh2/.darkbandと同じ(CSS冒頭のh2
        コメント参照)。`.cover`はこれまでwidth未指定だったため、dompdfが
        `.page`のborder-box(265mm)を差し引かずに宣言幅297mmをそのまま
        `.cover`自身の幅として使ってしまい、text-align:centerの基準となる
        ボックス自体が右へ16mm分広がっていた(実PDF確認で表紙の内容が
        全体的に右へ約16mmずれて見える不具合として発覚 ―― 依頼者指摘の
        「1ページ目がセンタリングされていない問題」の原因)。
    --}}
    .cover { width: 265mm; padding-top: 60mm; text-align: center; }
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
    {{--
        2026-08-22: 大分類帯(旧.bandrow独立table)と6カテゴリカードを
        1つのtableへ統合した(依頼者指定 ―― 別々のtableだと、それぞれが
        独立に列幅を計算するため、縦線が理論上は同じ265mm/6でもdompdfの
        丸め方次第でサブpx単位のズレが起こりうる。1つのtable+<colgroup>に
        することで、帯とカードが物理的に同じ列定義を共有し、縦線のズレを
        構造的に無くす)。列幅は<colgroup>の6本の<col>で一元管理する
        (旧.axcell{width:44.16mm}と同じ値、6列×44.16mm=264.96mm≈265mm、
        2026-08-04の実測に基づく値を踏襲)。
    --}}
    .brandwheeltbl { width: 265mm; margin-top: 2mm; table-layout: fixed; border-collapse: collapse; }
    .bandrow td { color: #fff; text-align: center; font-size: 10pt; font-weight: bold; padding: 1mm; }
    .axcell { padding: 0 1mm; }
    {{--
        2026-08-04: 軸セルの高さは38mmを確保する(docs/lead-report-layout/
        README.md ―― 4項目すべて該当したケースで下の帯へめり込んだ実績が
        あるため、必ず実PDFで目視確認すること)。
        2026-08-22: カード内部を「見出し/スコア/インジケータ/内容」の
        4領域に分け、見出し・スコア・インジケータの3領域を明示的な高さで
        固定した(依頼者指定 ―― 内容量によってカード内の縦位置がずれない
        ことを保証するため)。スコア("N / M件")・インジケータ(■□の並び)は
        いずれも既に1行で収まる固定フォーマットのため実質的にズレる要素
        ではなかったが、明示することで将来の文言変更でも保証が崩れない
        ようにする。
        2026-08-24: 38mm→30mm(前回改修)からさらに30mm→29mmへ縮小した
        (依頼者指摘 ―― 「カードの縦長感」「下部に大きな空白が残っている」)。
        1軸が4/4(4項目該当、下位要素名を4行の箇条書きで表示)になる
        worst-caseを実PDF実測したところ、axbody(border-top:none)の
        自然な必要高さは28.66mm(axcontent4行分含む)だったため、29mmは
        この実測値に0.34mmのバッファのみを持つ最小限の値。これ未満に
        縮めると4項目該当ケースでaxcontentの最終行がaxbodyの下端からはみ出す
        (table cell内のためクリップはされないが、自社/競合で1軸だけ
        4/4・他方は0/4という非対称worst-caseの場合に、はみ出した分だけ
        カード行の高さが自社/競合で食い違い、下の紺帯の開始Y座標がずれる)。
    --}}
    .axhead { border: 1px solid #E0E0E0; background: #F5F5F5; text-align: center; font-size: 9.5pt; font-weight: bold; padding: 0.6mm; height: 5mm; line-height: 1.1; }
    .axbody { border: 1px solid #E0E0E0; border-top: none; padding: 1.6mm 2mm; height: 29mm; }
    .axscore { height: 5mm; }
    .axcnt { font-size: 14pt; font-weight: bold; margin: 0; line-height: 1; }
    .axcnt small { font-size: 9pt; font-weight: normal; color: #6B6767; }
    .axind { height: 4.3mm; }
    .dots { margin: 0; }
    .dot { display: inline-block; width: 10px; height: 10px; margin-right: 3px; background: #DCDCDC; }
    .dot.on { background: #3A3FC0; }
    .hits2 { margin: 0; padding-left: 4mm; font-size: 8.5pt; line-height: 1.25; }
    .none2 { font-size: 9.5pt; color: #9A9A9A; margin: 0; }
    {{--
        2026-08-09: page-break-inside: avoidを追加(ユーザー承認の「上限付き」
        案)。実データ検証で、紺帯の残り余白が2mm程度しかないケースがあり、
        中途半端に1〜2行だけ次ページへ孤立する不具合が実PDF確認で見つかった。
        上限(件数・文字数)と2列化で紺帯の最大高さ自体を縮めた上で、万一それ
        でも収まらない場合は「途中で割れる」のではなく「丸ごと次ページへ」
        という失敗の仕方に倒す(dompdfはpage-break-insideを尊重する)。
        2026-08-22: 内部を3領域(.msgkey/.msgpos/.msgneg)に分け、
        min-heightで開始位置を揃えることを試みた。ただしheightでの完全
        固定は、90字+65字+65字の最大ケースで紺帯全体が次ページへ丸ごと
        押し出される不具合(page-break-inside:avoid)を実PDF確認で誘発した
        ため見送った(3項目とも任意表示で、いずれかが無い場合に後続が
        めり込まないようmin-heightのみ採用)。
        2026-08-24: 表からの余白(margin-top)を1mm→3mmに広げ(依頼者指摘
        ―― 表と紺帯がほぼ接していた)、padding・line-height・各領域の
        min-heightを実測に基づき見直した(依頼者指摘 ―― 「青ボックスが
        やや大きすぎる」「行間が広い」の両方に対応するため、内容が短い
        場合に残る空白を減らしつつ、90字などの最大ケースでも1〜2行の
        折り返しに収まる範囲でmin-heightを設定)。
        2026-08-24: 実測(PyMuPDFのget_drawings()で塗りつぶし矩形そのものを
        測定 ―― テキスト位置ではなく罫線/背景の実際の座標)で、
        width:265mm+padding:5mm(左右)の組み合わせが実際には275mm
        (=265+左右padding10mm)で描画されており、box-sizing:border-box
        (43行目)が効いていないことが判明した(依頼者指摘の「borderや
        padding込みで実測」で発覚 ―― 従来はテキスト位置だけで検証しており
        この10mmの右はみ出しに気付けていなかった)。dompdfのこの挙動に
        対する既知の直接対策が無いため、宣言幅から左右padding分を
        差し引いた255mmを指定することで実際の描画幅を265mmに合わせる
        (実PDF実測で281.00mm(表の右端と同じX)になることを確認済み)。
        同じパターン(width指定+水平padding)を使う改善提案ページの
        .onepoint/.recobox/.cmpoverviewにも同じ描画超過が見つかったが、
        今回の修正対象外(自社/競合分析ページのみ)のため触れていない。
    --}}
    .darkband { width: 255mm; background: #1D2088; color: #fff; padding: 0.8mm 5mm; margin-top: 3mm; page-break-inside: avoid; }
    .darkband p { margin: 0.3mm 0; font-size: 9.5pt; line-height: 1.3; }
    .msgkey { min-height: 7.5mm; }
    .msgpos, .msgneg { min-height: 7mm; }
    {{--
        2026-08-18: 「候補者に与える印象」の単一見出し配下にポジ/ネガを
        箇条書きで並べる形から、「ポジティブな印象」「ネガティブな印象」を
        別見出しとして明確に分離した(依頼者指定)。あわせて「キーメッセージと
        印象の読み取りにはAIを使用しています。」というAI利用の開示文言も
        削除した(依頼者指定 ―― UI/PDF上でAI利用を前面に出さない。バックエンド
        内部でAIを利用すること自体は変更しない)。
    --}}
    .impgood, .impbad { margin: 0.2mm 0 0; font-size: 9pt; line-height: 1.25; }
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
    .introlead { font-size: 11.5pt; margin: 0 0 2.5mm; }
    .grouptbl { margin-bottom: 2mm; }
    .grouptbl td { padding: 1.3mm 0; vertical-align: middle; }
    .gcell { width: 34mm; text-align: center; font-size: 10pt; font-weight: bold; padding: 2mm 1mm; }
    .gdesc { width: 105mm; font-size: 10pt; line-height: 1.5; padding-left: 4mm; }
    {{--
        2026-08-17: .introbody/.introcautionは元々139mm幅の列(画像の右隣)の
        内側にあったが、軸単位の説明を全幅パラグラフとして追加した際に
        `.page`直下の通常フローへ移した。h2/.darkbandと同じ理由
        (CSS冒頭のh2コメント参照)でwidth: 265mmを明示する。
        実PDF確認で、軸定義パラグラフ(.axisdefs)追加後は元の行間・余白のままだと
        introbody×2+introcautionが2ページ目に収まりきらず、ほぼ空白の3ページ目へ
        あふれる不具合が見つかったため、行間・余白を全体的に詰めて1ページに
        収める(この前置きページは分析結果に依存しない固定ページのため、
        一度収まる値を決めれば実データによって再びあふれることはない)。
    --}}
    .introbody { width: 265mm; font-size: 9.5pt; line-height: 1.4; margin: 0 0 1.5mm; }
    .axisdefs { width: 139mm; font-size: 8pt; line-height: 1.3; color: #4a4a4a; margin: 1.5mm 0 0; }
    .introcaution { width: 265mm; font-size: 8.5pt; line-height: 1.45; color: #5b5b5b; border-top: 1px solid #E0E0E0; padding-top: 1.5mm; margin: 0; }

    {{-- 自社ページの分析結果ページの合計件数ボックス。 --}}
    {{--
        2026-08-04: CSSのpadding-rightで列間の余白を作ると、table-layout:fixed
        下でdompdfが右端の列(レーダー画像)を宣言幅より狭く解決し、画像の
        右側が欠けて描画される不具合が実PDF確認で見つかった。padding-rightは
        使わず、実績のある方式(列間に幅固定のスペーサーtdを挟む)で余白を作る
        (画像を含む行にのみ適用。テキストのみの行はpadding方式で問題ない)。
    --}}
    .statrow td { vertical-align: top; }
    .statbox { border: 1px solid #E0E0E0; padding: 2.5mm 4mm; }
    .statbox .lab { font-size: 9.5pt; color: #6B6767; margin: 0 0 1.5mm; }
    .statbox .num { font-size: 26pt; font-weight: bold; line-height: 1; margin: 0; }
    .statbox .num small { font-size: 11pt; font-weight: normal; color: #6B6767; }
    {{-- 2026-08-17: 件数集計であることの技術的な注記を、メインコピーではなく
         小さな注釈として添える(依頼者指定 ―― 取得件数そのものの説明は
         メインコピーにしない)。 --}}
    .statbox .statnote { font-size: 7.5pt; color: #9A9A9A; margin: 1.5mm 0 0; line-height: 1.3; }
    {{-- 2026-08-25追加(修正5): 自社の合計matched件数が閾値未満のときの但し書き。 --}}
    .lowcontentnotice { font-size: 7.5pt; color: #6B6767; margin: 1.5mm 0 0; line-height: 1.4; }
    .swatch { display: inline-block; width: 9px; height: 9px; margin-right: 4px; }

    {{-- 「○△－の対比表」ページ。2026-08-08: ●／－の2値から○△－の3値へ変更。 --}}
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    .vslead { width: 265mm; font-size: 9pt; color: #6B6767; margin: 0 0 1mm; line-height: 1.25; }
    {{-- 2026-08-17追加: 比較結果サマリー(page5冒頭)。他の注記ボックス
         (.onepoint等)と同じ配色トーン(藍のleft-border+淡グレー背景)に揃える。 --}}
    {{-- 2026-08-17: 実PDF確認で、このボックスを追加した分だけ○△－の対比表
         (24項目表)がページ5に収まらず、丸ごとページ6へあふれる不具合が
         見つかったため、フォント・行間・余白を最小限に切り詰める。 --}}
    .cmpoverview { width: 265mm; border-left: 4px solid #1D2088; background: #F5F5F5; padding: 1.5mm 3mm; margin: 0 0 1.5mm; }
    .cmpoverview .t { font-size: 8.5pt; font-weight: bold; margin: 0 0 0.5mm; }
    .cmpoverview p { font-size: 8pt; line-height: 1.25; margin: 0; }
    {{-- グループ優劣バッジ(grpbar内の右寄せ小ラベル)。 --}}
    .grpverdict { font-size: 7.5pt; font-weight: normal; opacity: .85; }
    {{--
        凡例は表の近くに必ず置く(対比表ページの意味を誤読させないため
        ―― ○△－は正解/不正解の記号ではない、2ページ目の断り書きと矛盾
        しないこと。ユーザー指定)。
    --}}
    {{-- 2026-08-10: width:265mm→165mm(レーダーと横並びにしたため、CSS冒頭の
         h2コメントと同じ理由で明示が必要)。 --}}
    {{-- 2026-08-17: .cmpoverview追加に伴い、表全体(グループ帯+8行×3列+
         合計/参考の2行)がページに収まりきらない不具合が見つかったため、
         padding/marginを全体的に切り詰めて数mm分の余白を確保する。 --}}
    .cmplegend { width: 165mm; border: 1px solid #E0E0E0; background: #F5F5F5; padding: 1.3mm 4mm; margin: 0; }
    .cmplegend p { font-size: 8.5pt; color: #393636; line-height: 1.3; margin: 0; }
    .cmplegend .mk { display: inline-block; width: 5mm; font-weight: bold; }
    .vscell { width: 88.3mm; padding: 0 2mm 0 0; vertical-align: top; }
    .grpbar { color: #fff; font-size: 9.5pt; font-weight: bold; text-align: center; padding: 1mm; }
    .vstbl th { font-size: 8.5pt; font-weight: bold; padding: 1mm; border-bottom: 1px solid #E0E0E0; color: #6B6767; text-align: center; }
    .vstbl th.sub { text-align: left; }
    .vstbl td { font-size: 8.5pt; padding: 1mm; border-bottom: 1px solid #EFEFEF; }
    .vstbl td.sub { text-align: left; }
    .vstbl td.mk { text-align: center; width: 13mm; font-size: 10.5pt; }
    .mkon { color: #1D2088; font-weight: bold; }
    .mkon.cp { color: #E95446; }
    {{-- △(見出し・リンクラベルのみ)。○(mkon、自社紺/競合朱)・－(mkoff、
         淡灰)のどちらとも視覚的に紛れない中間色(アンバー)にする。 --}}
    .mktri { color: #B8860B; font-weight: bold; }
    .mkoff { color: #BFBFBF; }
    .vslegend { width: 265mm; font-size: 9pt; color: #6B6767; margin: 1mm 0 0; }
    .vsreflegend { width: 265mm; font-size: 8.5pt; color: #8A8A8A; margin: 0.5mm 0 0; }

    {{-- 「改善提案」ページ。 --}}
    {{-- widthを明示する理由は.lead1と同じ(2026-08-04、CSS冒頭のh2コメント参照)。 --}}
    .rlead { width: 265mm; font-size: 9.5pt; color: #6B6767; margin: 0 0 2mm; line-height: 1.4; }
    {{-- 2026-08-17: 改善提案AI(ワンポイント/詳細提言)追加に伴い、実PDF確認
         (worst-caseの長文AI出力)でページ下部の余白が10mm未満になる不具合が
         見つかったため、余白・行間を切り詰める。
         2026-08-19: 「中長期の差別化ポイント」ボックス追加分の高さを吸収する
         ため、余白・行間をさらに切り詰めた(フォントサイズは変更しない ――
         極端な縮小は禁止、docs/lead-report-layout/README.mdの検証方法論に
         従い実PDF確認で調整)。 --}}
    .onepoint { width: 265mm; border-left: 4px solid #1D2088; background: #F5F5F5; padding: 1.6mm 4mm; margin: 0 0 1.5mm; page-break-inside: avoid; }
    .onepoint .t { font-size: 10pt; font-weight: bold; margin: 0 0 1mm; }
    .onepoint p { font-size: 9.5pt; line-height: 1.4; margin: 0; }
    {{-- 2026-08-17追加: 改善提案AIの詳細提言パラグラフ。.onepointと同じ
         トーン(藍のleft-border+淡グレー背景)だが、証拠カードの直後に置く
         ため上マージンで区切る。 --}}
    .recobox { width: 265mm; border-left: 4px solid #1D2088; background: #F5F5F5; padding: 1.4mm 4mm; margin: 1mm 0 0; page-break-inside: avoid; }
    .recobox .t { font-size: 9.5pt; font-weight: bold; margin: 0 0 1mm; }
    .recobox p { font-size: 9pt; line-height: 1.3; margin: 0; }
    {{-- 2026-08-18追加: 「理由」(ワンポイント直下の地の文)・「具体的に
         追加すべき情報」(箇条書き)。 --}}
    .reasontext { width: 265mm; font-size: 9.5pt; color: #393636; line-height: 1.35; margin: 0.8mm 0 1.2mm; }
    .recobox .recolist { margin: 0; padding-left: 4mm; font-size: 9pt; line-height: 1.3; }
    {{-- 2026-08-19追加: 「中長期の差別化ポイント」。Quick Win系のボックス
         (.onepoint/.recobox、藍の左ボーダー)とは別テーマだと一目で分かる
         よう、既存パレットの「会社との距離」色(#2C7F96、groupBands参照)を
         左ボーダーに使う(新色は追加しない)。page-break-inside:avoidは、
         見出しだけがページ末尾に残り本文が次ページへ分離する不具合
         (実PDF確認で発見)を防ぐため。 --}}
    .diffbox { width: 265mm; border-left: 4px solid #2C7F96; background: #F5F5F5; padding: 1.4mm 4mm; margin: 1mm 0 0; page-break-inside: avoid; }
    .diffbox .t { font-size: 9.5pt; font-weight: bold; margin: 0 0 1mm; color: #2C7F96; }
    .diffbox p { font-size: 9pt; line-height: 1.3; margin: 0; }
    .gapbar td { padding: 0 0 0.8mm; font-size: 9pt; vertical-align: middle; }
    .gapbar .nm { width: 34mm; }
    .gapbar .bar { height: 4.3mm; display: block; }
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
    .rcard { border: 1px solid #E0E0E0; padding: 1.8mm 3mm; }
    .rcard .no { font-size: 9pt; color: #fff; background: #1D2088; padding: 0.6mm 2.2mm; }
    .rcard .nm { font-size: 11.5pt; font-weight: bold; margin: 0.8mm 0 0.6mm; }
    .rcard .q { font-size: 9pt; color: #6B6767; margin: 0 0 1mm; line-height: 1.25; }
    .rcard .lb { font-size: 8.5pt; color: #8A8A8A; margin: 0 0 0.5mm; }
    {{-- 2026-08-25更新: 所見(旧「御社のサイト／記述が見つかりませんでした」の
         2行)から提案への切替に伴い、現状注記は控えめな1行にする(依頼者指定
         「小さく、控えめに」)。 --}}
    .rcard .own { font-size: 8pt; color: #9A9A9A; margin: 0 0 1mm; }
    .rcard .cmp { font-size: 9pt; line-height: 1.3; margin: 0; border-left: 3px solid #E95446; padding-left: 2.5mm; }
    .rcell { width: 88.3mm; padding: 0 2mm 2mm 0; vertical-align: top; }

    {{--
        7ページ目(最終ページ)。2026-08-08: 旧3ブロック構成(.ctabox/.ctacell)は
        新文言(見出し+本文2段落のシンプルな構成)への差し替えに伴い削除した。
        2026-08-10: 連絡先確定に伴い.ctafoot(担当営業までご連絡くださいの
        仮文言)を.ctacontact(罫線ボックスでURLを大きく提示)に差し替え。
        あわせて.ctawrapにpadding-topを追加し、下半分が空白でバランスが
        悪かった問題に対応(ボックスを大きく・全体をやや下寄りにして
        ページ内の重心を中央付近に近づけた)。
    --}}
    {{-- 2026-08-17: 最終ページを大きなメインコピー＋短い補足＋CTAボタン中心の
         構成へ簡潔化(依頼者指定)。padding-topを拡大し、要素を絞った分
         全体の重心を中央に寄せる。 --}}
    {{--
        2026-08-17: dompdfは margin: 0 auto によるブロック中央寄せを
        信頼できる形で解決しない(width指定の組み合わせを変えても実PDF確認で
        改善しなかった)。`.cover`で実績のある「width明示 + text-align:center」
        パターンに寄せ、`.ctawrap`自体は265mm(ページの内容幅いっぱい)にして
        marginでの中央寄せをやめ、text-align:centerで子要素を中央寄せする
        (子側の中央寄せ方法は各クラスのコメント参照)。
    --}}
    .ctawrap { width: 265mm; text-align: center; padding-top: 34mm; }
    {{--
        2026-08-17: dompdfのmargin:0 autoによるブロック中央寄せが信頼できない
        (実PDF確認、.ctawrap/.ctacontactと同じ理由)ため、display:blockを
        やめてinline(imgの既定)のままにし、親`.ctawrap`のtext-align:center
        (子側のtext-align:centerはinline要素にのみ効く)に中央寄せを委ねる。
        横方向のmarginは持たせず、縦方向の余白だけ残す。
    --}}
    .ctalogo { width: 56mm; margin-bottom: 12mm; }
    .ctah { width: 265mm; font-size: 19pt; font-weight: bold; text-align: center; margin: 0 0 7mm; line-height: 1.6; }
    .ctasub { width: 265mm; font-size: 11.5pt; color: #6B6767; text-align: center; margin: 0 0 4mm; line-height: 1.9; }
    {{-- 2026-08-17: 同上の理由でmargin:autoをやめ、display:inline-blockに
         して親のtext-align:centerに中央寄せを委ねる。 --}}
    .ctacontact { display: inline-block; width: 200mm; margin-top: 16mm; text-align: center; }
    {{-- URL文字列をそのまま表示せず、ボタン風のラベル付きリンクにする
         (依頼者指定)。dompdfはbox-shadow/border-radiusの表現力が乏しいため、
         塗りの矩形+白文字の単純なボタンで表現する。 --}}
    .ctabtn { display: inline-block; background: #1D2088; color: #fff; text-decoration: none; font-size: 13pt; font-weight: bold; padding: 5mm 14mm; }
    .ctacontact .note { font-size: 9.5pt; color: #9A9A9A; margin: 5mm 0 0; line-height: 1.7; }

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

    // 2026-08-17追加: 前置きページ(2ページ目)で軸単位の説明を出すための
    // ルックアップ。config('brand_wheel.axes.*.definition')(既存、
    // OpenAiBrandWheelAnalysisProviderのプロンプトにも使われている定義文)を
    // そのまま流用する ―― 新しい文言を作らず既存定義の使い回しに留めることで、
    // 実際のAI判定基準とページ上の説明が食い違わないようにする(依頼者指定:
    // 「勝手に定義を変えず、既存定義をもとに説明してください」)。
    $axisDefinitionsByGroup = collect((array) config('brand_wheel.axes', []))
        ->groupBy('group')
        ->map(fn ($axes) => $axes->map(fn ($axis) => ['name_ja' => $axis['name_ja'], 'definition' => $axis['definition']])->values());

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
        <td style="width: 139mm; vertical-align: top; padding-top: 2mm;">
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
            {{--
                2026-08-17: 軸単位の説明(config('brand_wheel.axes.*.definition')、
                既存)を追加する(依頼者指定 ―― 「6カテゴリの意味を分かりやすく
                する」)。grouptbl(3行のtable)のtd内にネストして各行へ入れたところ、
                実PDF確認でdompdfのテーブル改ページ処理が破綻し、後続2ページが
                白紙化・3ページ目相当の内容が267mm(A4横210mmを大きく超過)まで
                あふれる重大な不具合が見つかった。table-in-table(grouptbl自体が
                外側tableのtd内)の入れ子構造が原因と見て、単純な1階層(外側table
                のtd直下の<p>)に置き換えて解消した。この位置(画像の右列、
                grouptblの直後)なら、画像の高さ(124mm)がすでに行の高さを
                決めているため、この段落の追加ぶんは新たな行の高さを生まない
                (画像の下に元々余っていた余白を使うだけ)。
            --}}
            <p class="axisdefs">
                @foreach ((array) config('brand_wheel.axes', []) as $axis)
                    <b>{{ $axis['name_ja'] }}</b>：{{ $axis['definition'] }}
                @endforeach
            </p>
        </td>
    </tr></table>
    <p class="introbody">6つの項目にはそれぞれ4つの下位要素があり、合計24項目です。中心の<b>Core Value(約束する価値)</b>は、その24項目を貫く「この会社が候補者に約束するもの」にあたります。</p>
    {{-- 2026-08-17: 「点数付けではなく、件数の集計です」という件数集計
         フレーミングを弱め、レポートの目的(候補者への伝わり方の分析)を
         主文にする(依頼者指定#3)。URL分析対象範囲の明記(依頼者指定#5、
         実装調査で確認: 採用ページ・トップページの記述のみを対象とし、
         サイト全体の自動巡回は行っていない)もここに追加する。 --}}
    {{-- 2026-08-18: 「この24項目のうち何件が...もあわせて示しています」という
         件数集計の補足説明を削除(依頼者指定 ―― 件数集計資料の印象を強めるため)。
         N/24等の数値表示自体は3・4ページの統計ボックス等に引き続き残す。 --}}
    <p class="introbody">本レポートでは、サイト上から確認できた情報をもとに、候補者に伝わる情報や印象を分析しています。</p>
    <p class="introbody" style="font-size: 9.5pt; color: #6B6767;">本分析は、ご提供いただいた採用ページ・トップページの記述を対象としており、サイト全体や他の関連ページを自動的に巡回して分析するものではありません。</p>
    <p class="introcaution">読み取れなかった項目は、その魅力が『無い』という意味ではありません。サイトにそう書かれていない、というだけです。また、採用ブランドは本来、グループインタビュー・口コミ・内定者や辞退者へのインタビュー・説明会・SNSなども併せて構築するものです。今回はそのうちサイトの記述のみを拝見しています。</p>
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
    'lowContentNotice' => $viewModel->selfLowContentNotice,
])

@if ($competitorReadable)
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
            $showCompetitorColumn = $competitorReadable;
            $groupVerdictByKey = collect($viewModel->groupTotals)->keyBy('group');
            $verdictBadge = fn (string $verdict) => match ($verdict) {
                'self_advantage' => '自社優位',
                'competitor_advantage' => '競合優位',
                default => '同程度',
            };
        @endphp
        <p class="vslead">24項目それぞれについて、サイトに該当する記述があったかどうかを3段階で示しています。凡例は下記のとおりです。</p>
        {{--
            2026-08-17追加: 比較サマリー(依頼者指定#11 ―― 単純な総合勝敗では
            なく「どの領域に情報差があるか」を示す)。BrandWheelComparisonSummary
            Composer::comparisonOverview()が総合計件数とグループ優劣
            (BrandWheelSubElementComparisonComposer::groupTotals())から機械的に
            導出する(AIには書かせない)。競合が読み取れない場合は出さない。
        --}}
        @if ($viewModel->comparisonOverview !== [])
            <div class="cmpoverview">
                <p class="t">比較結果サマリー</p>
                @foreach ($viewModel->comparisonOverview as $line)
                    <p>{{ $line }}</p>
                @endforeach
            </div>
        @endif

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
                    <div class="grpbar" style="background: {{ $band['color'] }};">{{ $band['label'] }}
                        @if ($showCompetitorColumn && $groupVerdictByKey->has($groupKey))
                            <span class="grpverdict">（{{ $verdictBadge($groupVerdictByKey[$groupKey]['verdict']) }}）</span>
                        @endif
                    </div>
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
    領域差・3項目は競合ありなら$viewModel->improvementFocus、競合なし
    (または読み取れない)なら$viewModel->improvementFocusSelfOnly
    (2026-08-10追加、いずれも決定的な規則で選定)が唯一の情報源。△は
    未該当扱いのまま(選定ロジックは無改修 ―― 自社△かつ競合○の項目も
    引き続き候補に含まれる、ユーザー指定)。

    2026-08-08: 下部の技術的提案ブロック(「あわせて、サイトの作りに
    ついて」)を削除した。4観点(測定結果)ページを削除したのに技術的提案
    だけ残すのは整合が取れないため(ユーザー判断)。

    2026-08-10: このページ自体を出さない条件を追加(ユーザー指定)。
    自社が読み取れない場合(status_messageを出すため)は常に出す。自社が
    読み取れる場合は、競合あり(improvementFocus)か、競合なしでも自社の
    「－」「△」項目が1件でもある(improvementFocusSelfOnly)場合にのみ出す
    ―― 自社24項目すべてが○(=composeSelfOnly()がnullを返す)場合だけ、
    このページを丸ごと省略する(白紙ページを作らないための保険。
    実運用ではまず起きない)。
--}}
@if (! $selfReadable || $viewModel->improvementFocus !== null || $viewModel->improvementFocusSelfOnly !== null)
<div class="page">
    <h2>改善提案</h2>
    <img class="logo-mark" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">

    @if (! $selfReadable)
        <p>{{ $selfWheel['status_message'] ?? '' }}</p>
    @else
        {{--
            2026-08-17: ワンポイントの文言を、改善提案AI
            (GenerateBrandWheelImprovementSuggestionJob)の生成結果へ切り替える
            (依頼者指定 ―― 一言で最優先アクションを示す)。AI未生成/失敗時は
            $viewModel->improvementOnePointが既存の決定的ロジック
            ($comparison['one_point'])へ自動フォールバックする
            (ReportViewModelBuilder参照、AI障害でレポート生成を止めない)。
        --}}
        @if ($viewModel->improvementOnePoint)
            <div class="onepoint">
                <p class="t">【ワンポイント】</p>
                <p>{{ $viewModel->improvementOnePoint }}</p>
            </div>
        @endif

        {{--
            2026-08-18追加: ワンポイントの理由(依頼者指定の構成 ―― ワンポイント
            →理由→自社と競合の差(既存)→具体的に追加すべき情報→中長期施策)。
            改善提案AI未生成/失敗時はnullのため非表示(既存のバー＋カードのみで
            成立する)。
        --}}
        @if ($viewModel->improvementReason)
            <p class="reasontext"><b>理由：</b>{{ $viewModel->improvementReason }}</p>
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
                <table style="width: 265mm; margin-top: 2mm;">
                    <tr>
                        @foreach ($focus['items'] as $i => $item)
                            <td class="rcell">
                                <div class="rcard">
                                    <span class="no">{{ $i + 1 }}</span>
                                    <p class="nm">{{ $item['sub_name'] }}</p>
                                    <p class="q">{{ $item['recommendation'] }}</p>
                                    <p class="own">（現在、サイトからは読み取れませんでした）</p>
                                    <p class="lb">比較サイトの記述</p>
                                    <p class="cmp">「{{ $item['competitor_evidence'] }}」</p>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </table>

                {{--
                    2026-08-18: 単一段落の「改善のご提案」(旧recommendation)を、
                    依頼者指定の構成に合わせて「具体的に追加すべき情報」(箇条書き、
                    最大3項目)＋「中長期の差別化ポイント」(該当する場合のみ)に
                    分割した。既存のグループ差バー＋証拠カード(無改修、決定的
                    ロジック)は「自社と競合の差」の根拠として残し、その下に
                    結論(具体的に追加すべき情報=今すぐ優先して改善すること)を
                    続ける構成にする。未生成/失敗時は非表示(既存のバー＋
                    カードのみで成立する)。

                    2026-08-19: 「中長期の差別化ポイント」を、単なる末尾の
                    1行(旧.midterm)から、Quick Win系ボックスと明確に分離した
                    独立ボックス(.diffbox)へ格上げした(依頼者指定 ――
                    「競合との差を埋める提案」と「競合も弱い領域での差別化
                    提案」を役割として分けるため)。中身
                    ($viewModel->improvementMidTermAction)は
                    mutually_unmatched_items(自社・競合とも未充足の項目)から
                    AIが選んだ1テーマのみ(OpenAiBrandWheelImprovementSuggestion
                    Provider::buildPrompt()参照、決め打ちのカテゴリではない)。
                --}}
                @if (count($viewModel->improvementRecommendedContents) > 0)
                    <div class="recobox">
                        <p class="t">具体的に追加すべき情報</p>
                        <ul class="recolist">
                            @foreach ($viewModel->improvementRecommendedContents as $content)
                                <li>{{ $content }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($viewModel->improvementMidTermAction)
                    <div class="diffbox">
                        <p class="t">中長期の差別化ポイント</p>
                        <p>{{ $viewModel->improvementMidTermAction }}</p>
                    </div>
                @endif

                <p class="rlead" style="margin: 1.2mm 0 0;">サイト上の情報追加だけでなく、実態として存在する魅力の整理も重要です。</p>
            @endif
        @elseif ($viewModel->improvementFocusSelfOnly)
            {{--
                2026-08-10: 競合が無い(または読み取れない)診断向け。
                「比較サイトが無いため、領域ごとの比較はご用意できません。」の
                1行だけでページの大半が空白になり、営業資料として成立しない
                という指摘(ユーザー)への対応。競合の実データを使わず、自社の
                「－」「△」項目(BrandWheelImprovementFocusComposer::
                composeSelfOnly()、決定的な規則で選定)だけで構成する。
                最終ページの「3〜5社と比較しませんか」への導線として機能させる。
            --}}
            @php
                $focusSelf = $viewModel->improvementFocusSelfOnly;
                $selectedLabelSelf = $groupBands[$focusSelf['selected_group']]['label'] ?? $focusSelf['selected_group'];
                $selfOnlyReasonLabel = fn (string $reason) => $reason === 'label_only'
                    ? '（現在、見出し・リンクラベルのみで、具体的な記述は見つかりませんでした）'
                    : '（現在、サイトからは読み取れませんでした）';
            @endphp
            <p class="rlead">3つの領域のうち、サイトの記述から読み取れた項目が最も少なかったのは「{{ $selectedLabelSelf }}」でした。この領域から、候補者が知りたがる項目を{{ count($focusSelf['items']) }}件挙げます。</p>

            {{-- 自社のみの3列版(nm/v/bar)。競合が無いため.gapbarの5列版
                 (nm/v/bar/v/bar)は使わず、赤い比較バーは出さない
                 (ユーザー指定)。 --}}
            <table class="gapbar" style="width: 265mm; table-layout: auto;">
                @foreach ($focusSelf['groups'] as $group)
                    @php
                        $labelSelf = $groupBands[$group['group']]['label'] ?? $group['group'];
                        $selfRatioSelf = $group['max_count'] > 0 ? $group['self_count'] / $group['max_count'] * 100 : 0;
                    @endphp
                    <tr>
                        <td class="nm">{{ $labelSelf }}</td>
                        <td class="v">自社 {{ $group['self_count'] }} / {{ $group['max_count'] }}</td>
                        <td style="width: 108mm;"><span class="bar" style="background: #3A3FC0; width: {{ number_format($selfRatioSelf, 1) }}%;"></span></td>
                    </tr>
                @endforeach
            </table>

            @if (count($focusSelf['items']) === 0)
                <p class="gnone" style="margin-top: 3mm;">該当する項目はありませんでした</p>
            @else
                <table style="width: 265mm; margin-top: 2mm;">
                    <tr>
                        @foreach ($focusSelf['items'] as $i => $item)
                            <td class="rcell">
                                <div class="rcard">
                                    <span class="no">{{ $i + 1 }}</span>
                                    <p class="nm">{{ $item['sub_name'] }}</p>
                                    <p class="q">{{ $item['recommendation'] }}</p>
                                    <p class="own">{{ $selfOnlyReasonLabel($item['self_reason']) }}</p>
                                </div>
                            </td>
                        @endforeach
                    </tr>
                </table>

                @if (count($viewModel->improvementRecommendedContents) > 0)
                    <div class="recobox">
                        <p class="t">具体的に追加すべき情報</p>
                        <ul class="recolist">
                            @foreach ($viewModel->improvementRecommendedContents as $content)
                                <li>{{ $content }}</li>
                            @endforeach
                        </ul>
                    </div>
                @endif
                @if ($viewModel->improvementMidTermAction)
                    <div class="diffbox">
                        <p class="t">中長期の差別化ポイント</p>
                        <p>{{ $viewModel->improvementMidTermAction }}</p>
                    </div>
                @endif

                <p class="rlead" style="margin: 1.2mm 0 0;">サイト上の情報追加だけでなく、実態として存在する魅力の整理も重要です。</p>
            @endif
        @endif
    @endif
</div>
@endif

{{--
    7. 最終CTAページ。2026-08-17: 長い説明文(「サイトの改善をすれば課題が
    解決するとは限りません」+本文2段落)を削除し、営業CTAに集中させる
    (依頼者指定 ―― 「レポートをここまで読んだユーザーに長文を読ませない
    ことを優先する」)。

    【重要】この新文言は、2026-08-08にdocs/lead-report-layout/README.mdへ
    記録した「旧CTA『他社比較(3〜5社)』は使わない」という制約と直接矛盾する
    (当時: 「もっとサイトを比較しましょう」とだけ持ちかける文面は避ける、と
    明記していた)。今回の依頼文が「さらに3〜5社の競合採用サイトと比較し…」
    という文言を明示的にメインコピー例として指定しているため、今回はその
    指示を優先する。方針転換であることを実装報告で明記する。

    連絡先は2026-08-10時点の方針を維持: https://leggenda-co.web-tools.biz/inquiry
    (公式サイトの問い合わせページ)のみ掲載し、外部フォームツール本体の
    URLは掲載しない(印刷物に自社ドメイン以外のURLが出るとフィッシングを
    疑われる・フォームツールを乗り換えた際にPDFの刷り直しが必要になるため)。
    電話番号は掲載しない(問い合わせページに「現在、お電話の受付を停止して
    おります」と明記されているため)。URLをそのまま長文表示するのではなく、
    ボタン風のラベル付きリンクにする(依頼者指定)。発行日は表紙と同じ
    $viewModel->generatedAtLabelを参照し、二重管理しない。
--}}
<div class="page cta">
    <div class="ctawrap">
        <img class="ctalogo" src="data:image/png;base64,{{ $leggendaLogoImageBase64 }}" alt="LEGGENDA">
        <p class="ctah">さらに3〜5社の競合採用サイトと比較し、<br>御社が優先して改善すべき課題を整理しませんか？</p>
        <p class="ctasub">詳細な比較結果をもとに、採用課題についてディスカッションします。</p>

        <div class="ctacontact">
            <p class="url"><a href="https://leggenda-co.web-tools.biz/inquiry" class="ctabtn">競合比較について相談する</a></p>
            <p class="note">お問い合わせの際は、本レポートの発行日（{{ $viewModel->generatedAtLabel }}）と貴社名をお知らせください。</p>
        </div>
    </div>
</div>

</body>
</html>
