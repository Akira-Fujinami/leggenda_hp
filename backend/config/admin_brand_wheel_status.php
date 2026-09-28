<?php

/*
|--------------------------------------------------------------------------
| 依頼CE-2(2026-09-28): 管理画面「Brand Wheel」表の状態表示専用の文言
|--------------------------------------------------------------------------
| 背景: BrandWheelLeadResponseComposer::resolveStatus()は、success以外の
| 5状態(error/pending/recruit_page_unreadable/insufficient_input/
| no_matched_content)をすべてリード向けJSON/レポートでは同じ
| 「axes:[]」に畳んでいる(依頼CD-1調査で判明)。依頼CD-3で管理画面の
| Brand Wheel表に自社の非success行への赤系強調は入ったが、「なぜ
| 非successなのか」までは出ていなかった(生のstatus文字列
| ―― 'insufficient_input'等の内部値そのまま ―― を表示していただけ)。
|
| ここでは2つを表示する(admin/analyses/show.blade.php「Brand Wheel」表、
| Admin\AnalysisController::show()参照):
| - status_labels: 状態列に出す短い日本語ラベル(内部値の置き換え)
| - 理由列の長い説明文は、この設定を新設せず既存の
|   config('brand_wheel.status_messages')(BrandWheelLeadResponseComposer::
|   compose()が既にリード向け画面で使っている、5状態ぶんの確定済み文言)を
|   そのまま再利用する ―― 同じ意味の文言を2箇所に持たない(依頼者の
|   既存方針、他のconfigファイルの重複禁止コメントと同じ考え方)。
|   brand_wheel.php自体は変更しない(しきい値・軸定義・プロンプトを
|   含む機微なファイルのため、この依頼では一切触らない)。
|
| resolveStatus()自体の畳み込み(API/JSON応答形状)は変更していない ――
| ここはAdmin\AnalysisController::show()がBrandWheelLeadResponseComposer::
| compose()を呼んで得た結果を、管理画面表示用にラベルへ変換するだけの
| 追加であり、既存の返り値構造・リード向け挙動には一切影響しない。
*/

return [

    'status_labels' => [
        'success' => '成功',
        'error' => 'エラー',
        'pending' => '処理中',
        'recruit_page_unreadable' => '採用ページ取得失敗',
        'insufficient_input' => '本文不足',
        'no_matched_content' => '該当なし',
    ],

];
