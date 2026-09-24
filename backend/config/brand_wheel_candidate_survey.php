<?php

/*
|--------------------------------------------------------------------------
| 依頼CB(2026-09-24): 24項目(config('brand_wheel.axes'))と、候補者調査の
| 項目・自社サイトの導線名との対応表。
|--------------------------------------------------------------------------
| 営業資料へ差し込む「足りないもの」(CB-2)・「自社サイトの階層図」(CB-3)の
| 2枚が、この表に依存する。依頼者から確定版が届くまで、依頼書に別添された
| 素案(brandwheel_mapping_draft.xlsxと同じ内容)をそのまま入れている ――
| 表の中身を差し替えるだけで、コードを一切変更せずに2枚の文言が変わる
| (依頼者指定)。
|
| キーは axis_key => sub_key の2階層(config('brand_wheel.axes.*.sub_elements')
| と同じキー)。この表側で項目名を持たず、キーの対応だけを持たせている
| ―― 項目名(sub_name)自体はconfig('brand_wheel.axes')が唯一の情報源のまま
| であり、この表を差し替えても24項目の定義(名前・分類)は変わらない
| (依頼者指定「24項目・6軸の定義を変えないこと」)。
|
| 各項目:
|   survey_item: 対応する候補者調査の項目名。「該当なし」の項目はnull
|                (捏造しない ―― percentageと必ずセットでnull)。
|   percentage:  survey_itemを選んだ割合(%、小数第1位)。survey_itemが
|                nullのときは必ずnull。
|   site_flow_name: 自社サイトでこの項目に対応する導線名(ページ・セクション
|                名の例)。CB-3(階層図)が「巡回した範囲では見つからなかった
|                導線」として、追加を推奨する候補にそのまま使う。該当する
|                導線が無い項目(例: 優越感)はnull ―― 「ありません」と
|                断定しないため、null の項目はCB-3の推奨一覧にそもそも
|                含めない(候補として挙げようがないため)。
|
| 出典(CB-2で必ず明記すること、依頼者指定の文言・URL):
|   株式会社OTOGI調べ(2025年12月、20〜40代の求職経験者500名、3つまで選択)
|   https://prtimes.jp/main/html/rd/p/000000015.000136858.html
*/

return [

    'source_note' => '出典：株式会社OTOGI調べ（2025年12月、20〜40代の求職経験者500名、3つまで選択）'
        .'https://prtimes.jp/main/html/rd/p/000000015.000136858.html',

    'mapping' => [

        'will_activity' => [
            'purpose' => ['survey_item' => '企業のビジョンや理念', 'percentage' => 16.0, 'site_flow_name' => 'ビジョン・理念'],
            'business_expansion' => ['survey_item' => '企業の事業・サービス', 'percentage' => 17.0, 'site_flow_name' => '事業を知る'],
            'project_initiative' => ['survey_item' => '実績・支援事例・プロジェクト', 'percentage' => 6.2, 'site_flow_name' => 'プロジェクト事例'],
            'social_contribution' => ['survey_item' => null, 'percentage' => null, 'site_flow_name' => 'サステナビリティ'],
        ],

        'asset' => [
            'brand_recognition' => ['survey_item' => '実績・支援事例・プロジェクト', 'percentage' => 6.2, 'site_flow_name' => '実績・受賞'],
            'competitiveness' => ['survey_item' => '企業の事業・サービス', 'percentage' => 17.0, 'site_flow_name' => '事業を知る／強み'],
            'scale_influence' => ['survey_item' => null, 'percentage' => null, 'site_flow_name' => '数字で見る'],
            'office_facility' => ['survey_item' => '働き方や職場環境', 'percentage' => 17.0, 'site_flow_name' => 'オフィス紹介'],
        ],

        'personality' => [
            'leadership' => ['survey_item' => '代表・経営層のインタビュー', 'percentage' => 13.4, 'site_flow_name' => 'トップメッセージ'],
            'org_structure' => ['survey_item' => null, 'percentage' => null, 'site_flow_name' => '組織・体制'],
            'company_character' => ['survey_item' => 'カルチャー・社風', 'percentage' => 11.4, 'site_flow_name' => 'カルチャー・社風'],
            'core_values' => ['survey_item' => '企業のビジョンや理念', 'percentage' => 16.0, 'site_flow_name' => 'バリュー・行動指針'],
        ],

        'relationship' => [
            'colleagues' => ['survey_item' => '社員インタビュー', 'percentage' => 19.4, 'site_flow_name' => '社員を知る'],
            'atmosphere' => ['survey_item' => '会社のイベント', 'percentage' => 8.0, 'site_flow_name' => '社内イベント'],
            'physical_freedom' => ['survey_item' => '残業時間や有給取得の客観データ', 'percentage' => 12.6, 'site_flow_name' => '数字で見る働き方'],
            'mental_freedom' => ['survey_item' => '働き方や職場環境', 'percentage' => 17.0, 'site_flow_name' => '働き方を知る'],
        ],

        'emotional_benefit' => [
            'pride' => ['survey_item' => '社員インタビュー', 'percentage' => 19.4, 'site_flow_name' => '社員を知る'],
            'talkable' => ['survey_item' => '社員インタビュー', 'percentage' => 19.4, 'site_flow_name' => '社員を知る'],
            'satisfaction' => ['survey_item' => '希望するポジションの仕事・業務内容', 'percentage' => 24.6, 'site_flow_name' => '仕事を知る'],
            'superiority' => ['survey_item' => null, 'percentage' => null, 'site_flow_name' => null],
        ],

        'financial_benefit' => [
            'salary_level' => ['survey_item' => '給与体系や評価制度', 'percentage' => 16.4, 'site_flow_name' => '給与・評価制度'],
            'benefits' => ['survey_item' => '福利厚生', 'percentage' => 12.4, 'site_flow_name' => '福利厚生'],
            'growth_opportunity' => ['survey_item' => '実現できるキャリアパス', 'percentage' => 23.8, 'site_flow_name' => 'キャリアパス'],
            'employment_stability' => ['survey_item' => null, 'percentage' => null, 'site_flow_name' => '数字で見る'],
        ],
    ],

];
