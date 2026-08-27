<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 依頼AF-2(2026-08-27): 依頼W-2で「理由」(reason)を非表示にしたのは、
 * ワンポイントが数値由来(BrandWheelImprovementFocusComposerが選んだ項目)に
 * 差し替わったのに、reasonはAIが別に選んだ項目について書かれたままで
 * 矛盾していたためだった(ReportViewModelBuilder:260)。矛盾は消えたが
 * 埋め戻しておらず、改善提案ページの下半分が白いままになっていた。
 *
 * 既存のreason列(自社単独の改善提案ページでのみ使用、AIが自身で選んだ
 * focus_sub_element_keysについて書く)とは別に、新しい列を追加する ――
 * 「実際に表示されているカードの項目についてのみ書く」という異なる制約
 * (依頼者指定)を持つため、reason列の意味を条件によって変えるのではなく、
 * 独立した列にする。
 *
 * - focus_items_reason: BrandWheelImprovementFocusComposer::compose()が
 *   決定的に選んだ項目(competitor_matched && !self_matched)について、
 *   なぜ優先すべきかをAIに書かせたテキスト。
 * - focus_items_reason_sub_names: そのAI呼び出し時点でcompose()が選んで
 *   いた項目のsub_name一覧(JSON配列)。レポート生成時に再計算した
 *   $improvementFocus['items']のsub_name一覧と完全一致する場合のみ、
 *   focus_items_reasonを表示する(ReportViewModelBuilder参照) ――
 *   「カードの項目と理由の対象が一致していること」を構造的に保証するため。
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('brand_wheel_improvement_suggestions', function (Blueprint $table) {
            $table->text('focus_items_reason')->nullable()->after('reason');
            $table->json('focus_items_reason_sub_names')->nullable()->after('focus_items_reason');
        });
    }

    public function down(): void
    {
        Schema::table('brand_wheel_improvement_suggestions', function (Blueprint $table) {
            $table->dropColumn(['focus_items_reason', 'focus_items_reason_sub_names']);
        });
    }
};
