<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * BrandWheelAnalysisProviderの出力を保存する。ai_analysis_resultsと同じ
 * 設計方針(website_analysis_id単位・input_hashによる冪等性チェック)に
 * 揃えるが、完全に独立したテーブルとして持つ ―― 既存のAI分析・スコアリング
 * (7カテゴリ100点)には一切参照されない別サブシステムのため。
 *
 * 【保存しないものの明記】
 * - 生のプロンプト全文・AIレスポンス全文は保存しない(ai_analysis_resultsと
 *   同じ方針)。採用ページ/トップページの本文がプロンプトにそのまま含まれる
 *   ため、全文を保存するとサイト本文の複製をもう1箇所に持つことになり、
 *   保持範囲が不必要に広がる。パース済みの判定結果(下位要素キー・抜粋・
 *   state)と入力のハッシュ・トークン数のみを保存すれば、再現性の検証
 *   (同一input_hashでの重複回避)には十分。
 * - リードの個人情報・企業識別情報(会社名・担当者名・電話番号)は
 *   一切保存しない ―― BrandWheelAnalysisInputFactoryがそもそもLeadSessionに
 *   依存しない設計のため、構造的に混入しない。
 * - 数値スコア・ランキング・パーセンテージ・5段階評価は一切持たない。
 *   axis_state_countsは{read, partial, unread}の内訳という事実のカウントの列。
 *
 * statusには'pending'/'running'/'success'/'error'に加え、'insufficient_input'
 * (採用ページ本文・トップページ本文の合計文字数が
 * config('brand_wheel.insufficient_input_min_total_chars')未満で、AIを
 * 呼ばずに評価不可としたケース)を持つ。この場合axes/axis_state_counts等は
 * すべてnullのままにし、"6軸すべてunread"という体裁の整った結果と
 * 混同されないようにする(2026-07-29の指摘 ―― 「サイトに記述が読み取れ
 * なかった」と「サイトの記述を読みに行けなかった」を区別するため)。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brand_wheel_analysis_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('analysis_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_analysis_id')->nullable()->constrained()->cascadeOnDelete();
            // insufficient_input(AIを呼ばずに評価不可とした)の場合はnull。
            $table->string('provider')->nullable();
            $table->string('model')->nullable();
            $table->string('status');
            // config/brand_wheel.phpの軸定義・教師データ・閾値のうち、どのバージョンで
            // 生成された結果かを判別するための値(OpenAiBrandWheelAnalysisProvider::
            // PROMPT_VERSION)。mockプロバイダの結果はnull。
            $table->string('prompt_version')->nullable();
            // 6軸分の {axis_key, matched_sub_elements, discarded_sub_elements,
            // claimed_sub_element_count, state} をそのまま配列で保存する。
            $table->jsonb('axes')->nullable();
            $table->boolean('core_value_readable')->nullable();
            $table->text('core_value_evidence')->nullable();
            $table->jsonb('quality_dimension_notes')->nullable();
            $table->jsonb('cautions')->nullable();
            // {read: N, partial: N, unread: N}(合計は常に6)。read+partialを
            // 合算した単一の件数は持たない ―― 3状態を区別するために設けた
            // 状態を、合算によって再び消してしまうため(2026-07-29の指摘)。
            $table->jsonb('axis_state_counts')->nullable();
            $table->boolean('is_mock')->default(false);
            // 入力(BrandWheelAnalysisInputの内容)のハッシュ。再生成要求が来ても
            // 入力が変わっていなければAPIへ再送しない冪等性チェックに使う。
            $table->string('input_hash');
            // AI_MAX_INPUT_TOKENS超過により入力が切り詰められた結果かどうか
            // (BrandWheelAnalysisInput::inputTruncatedをそのまま複製)。
            $table->boolean('input_truncated')->default(false);
            // 採用ページ/トップページそれぞれの取得状態
            // {recruit_page: 'read'|'absent'|'unreadable', home_page: ...}。
            // #97の2通目メールで、insufficient_inputの原因がサイト側の事情
            // (absent)かストレージ側の事情(unreadable)かを判別するために使う。
            $table->jsonb('source_pages')->nullable();
            $table->unsignedInteger('usage_input_tokens')->nullable();
            $table->unsignedInteger('usage_output_tokens')->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->string('error_code')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('generated_at')->nullable();
            // 2通目(ブランド・ホイール分析結果)メールの送信済み管理。社内スタッフ
            // 向けとリード企業向けは受信者・送信条件・内容がすべて異なる独立した
            // メールのため、2列に分けて別々に冪等性を保証する ―― 片方だけ送信に
            // 失敗した場合に、成功した側を再送せず失敗した側だけ再送できる必要が
            // あるため(2026-07-30の指摘)。
            $table->timestamp('staff_notified_at')->nullable();
            $table->timestamp('lead_notified_at')->nullable();
            $table->timestamps();

            $table->index(['website_analysis_id', 'created_at']);
            $table->index(['analysis_id', 'created_at']);
            $table->index('input_hash');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('brand_wheel_analysis_results');
    }
};
