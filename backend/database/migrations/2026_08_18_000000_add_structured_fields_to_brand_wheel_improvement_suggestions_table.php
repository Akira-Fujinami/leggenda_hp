<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 改善提案(page6)AIの出力を「一般論からの脱却」水準まで踏み込ませるための
 * 構造拡張(2026-08-18、クライアントレビュー対応)。
 *
 * 既存のone_point(ワンポイント)/recommendation(旧: 結論〜具体策を1段落で
 * 書かせていたフィールド、後方互換のため列自体は残すが新UIでは表示しない)に
 * 加えて、以下を追加する:
 * - reason: ワンポイントの理由(2〜3文、根拠の説明)
 * - recommended_contents: 具体的に追加すべき情報(最大3項目、JSON配列)
 * - mid_term_action: 中長期施策(該当する場合のみ、1〜2文)
 * - quick_win: 選ばれた最優先施策がQuick Win(実行しやすく効果も見込める)か
 * - implementation_difficulty / candidate_impact: low/medium/high の内部判定
 *   (UIに直接出す必須要件ではないが、優先順位判断の根拠としてAIに構造化
 *   出力させ、後から検証・調整できるようにする)
 * - gap_closing / differentiation_opportunities: AIが内部的に分類した
 *   ギャップ埋め対象/差別化対象の下位要素名(JSON配列、UIラベルとしては
 *   出さない内部ロジックの可視化用)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_wheel_improvement_suggestions', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('recommendation');
            $table->json('recommended_contents')->nullable()->after('reason');
            $table->text('mid_term_action')->nullable()->after('recommended_contents');
            $table->boolean('quick_win')->nullable()->after('mid_term_action');
            $table->string('implementation_difficulty')->nullable()->after('quick_win');
            $table->string('candidate_impact')->nullable()->after('implementation_difficulty');
            $table->json('gap_closing')->nullable()->after('candidate_impact');
            $table->json('differentiation_opportunities')->nullable()->after('gap_closing');
        });
    }

    public function down(): void
    {
        Schema::table('brand_wheel_improvement_suggestions', function (Blueprint $table) {
            $table->dropColumn([
                'reason', 'recommended_contents', 'mid_term_action',
                'quick_win', 'implementation_difficulty', 'candidate_impact',
                'gap_closing', 'differentiation_opportunities',
            ]);
        });
    }
};
