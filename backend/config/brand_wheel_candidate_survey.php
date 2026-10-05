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
|   survey_option: 対応する候補者調査の選択肢のキー('options'のキー)。
|                「該当なし」の項目はnull(捏造しない)。選択肢の名前・割合は
|                ここでは持たず、'options'の1か所にだけ置く。
|   site_flow_name: 自社サイトでこの項目に対応する導線名(ページ・セクション
|                名の例)。CB-3(階層図)が「巡回した範囲では見つからなかった
|                導線」として、追加を推奨する候補にそのまま使う。該当する
|                導線が無い項目(例: 優越感)はnull ―― 「ありません」と
|                断定しないため、null の項目はCB-3の推奨一覧にそもそも
|                含めない(候補として挙げようがないため)。
|
| 依頼CL-2(2026-10-05): 'options'(調査の選択肢14件の名前・割合)を、24項目の
| 対応表('mapping')から独立して持つようにした。従来は選択肢の名前と割合を
| 24項目の側に重複して書いていたため、どの項目にも対応しない選択肢
| (例: 研修制度 7.4%)をconfig上に表せなかった。'mapping'側は'options'の
| キーを参照するだけにしたので、同じ名前・割合を2か所に書くことはない。
| 'mapping'の対応づけの中身(どの項目がどの選択肢か)は依頼CB-2当時の素案を
| そのまま移しており、見直していない ―― 依頼者が確定版を返したら、この
| ファイルの'mapping'(必要なら'options')の差し替えだけで反映できる。
|
| 出典(CB-2で必ず明記すること、依頼者指定の文言・URL):
|   株式会社OTOGI調べ(2025年12月、20〜40代の求職経験者500名、3つまで選択)
|   https://prtimes.jp/main/html/rd/p/000000015.000136858.html
*/

return [

    'source_note' => '出典：株式会社OTOGI調べ（2025年12月、20〜40代の求職経験者500名、3つまで選択）'
        .'https://prtimes.jp/main/html/rd/p/000000015.000136858.html',

    /*
    | 調査の選択肢(14件、割合の高い順に並べてある。同率は記載順)。
    | キー => name(調査上の選択肢名)・percentage(%、小数第1位)。
    | 割合の降順への並べ替えはApp\Services\Report\CandidateSurveyCatalog::
    | options()が行う(この並び順に依存しない)。
    */
    'options' => [
        'job_content' => ['name' => '希望するポジションの仕事・業務内容', 'percentage' => 24.6],
        'career_path' => ['name' => '実現できるキャリアパス', 'percentage' => 23.8],
        'employee_interview' => ['name' => '社員インタビュー', 'percentage' => 19.4],
        'business_service' => ['name' => '企業の事業・サービス', 'percentage' => 17.0],
        'work_environment' => ['name' => '働き方や職場環境', 'percentage' => 17.0],
        'salary_evaluation' => ['name' => '給与体系や評価制度', 'percentage' => 16.4],
        'vision_philosophy' => ['name' => '企業のビジョンや理念', 'percentage' => 16.0],
        'executive_interview' => ['name' => '代表・経営層のインタビュー', 'percentage' => 13.4],
        'overtime_leave_data' => ['name' => '残業時間や有給取得の客観データ', 'percentage' => 12.6],
        'benefits' => ['name' => '福利厚生', 'percentage' => 12.4],
        'culture' => ['name' => 'カルチャー・社風', 'percentage' => 11.4],
        'company_event' => ['name' => '会社のイベント', 'percentage' => 8.0],
        'training' => ['name' => '研修制度', 'percentage' => 7.4],
        'achievements_projects' => ['name' => '実績・支援事例・プロジェクト', 'percentage' => 6.2],
    ],

    'mapping' => [

        'will_activity' => [
            'purpose' => ['survey_option' => 'vision_philosophy', 'site_flow_name' => 'ビジョン・理念'],
            'business_expansion' => ['survey_option' => 'business_service', 'site_flow_name' => '事業を知る'],
            'project_initiative' => ['survey_option' => 'achievements_projects', 'site_flow_name' => 'プロジェクト事例'],
            'social_contribution' => ['survey_option' => null, 'site_flow_name' => 'サステナビリティ'],
        ],

        'asset' => [
            'brand_recognition' => ['survey_option' => 'achievements_projects', 'site_flow_name' => '実績・受賞'],
            'competitiveness' => ['survey_option' => 'business_service', 'site_flow_name' => '事業を知る／強み'],
            'scale_influence' => ['survey_option' => null, 'site_flow_name' => '数字で見る'],
            'office_facility' => ['survey_option' => 'work_environment', 'site_flow_name' => 'オフィス紹介'],
        ],

        'personality' => [
            'leadership' => ['survey_option' => 'executive_interview', 'site_flow_name' => 'トップメッセージ'],
            'org_structure' => ['survey_option' => null, 'site_flow_name' => '組織・体制'],
            'company_character' => ['survey_option' => 'culture', 'site_flow_name' => 'カルチャー・社風'],
            'core_values' => ['survey_option' => 'vision_philosophy', 'site_flow_name' => 'バリュー・行動指針'],
        ],

        'relationship' => [
            'colleagues' => ['survey_option' => 'employee_interview', 'site_flow_name' => '社員を知る'],
            'atmosphere' => ['survey_option' => 'company_event', 'site_flow_name' => '社内イベント'],
            'physical_freedom' => ['survey_option' => 'overtime_leave_data', 'site_flow_name' => '数字で見る働き方'],
            'mental_freedom' => ['survey_option' => 'work_environment', 'site_flow_name' => '働き方を知る'],
        ],

        'emotional_benefit' => [
            'pride' => ['survey_option' => 'employee_interview', 'site_flow_name' => '社員を知る'],
            'talkable' => ['survey_option' => 'employee_interview', 'site_flow_name' => '社員を知る'],
            'satisfaction' => ['survey_option' => 'job_content', 'site_flow_name' => '仕事を知る'],
            'superiority' => ['survey_option' => null, 'site_flow_name' => null],
        ],

        'financial_benefit' => [
            'salary_level' => ['survey_option' => 'salary_evaluation', 'site_flow_name' => '給与・評価制度'],
            'benefits' => ['survey_option' => 'benefits', 'site_flow_name' => '福利厚生'],
            'growth_opportunity' => ['survey_option' => 'career_path', 'site_flow_name' => 'キャリアパス'],
            'employment_stability' => ['survey_option' => null, 'site_flow_name' => '数字で見る'],
        ],
    ],

];
