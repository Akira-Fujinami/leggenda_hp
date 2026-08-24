<?php

namespace App\Services\BrandWheel;

/**
 * 文字数上限による切り詰めを、文の途中で切らないようにする共通処理
 * (2026-08-25、営業資料として体裁が悪いという指摘への対応)。
 *
 * 上限内に収まる最後の句点(。)まで残す。句点が1つも無い場合のみ、
 * 従来どおり上限で切って末尾に「…」を付ける。
 *
 * BrandWheelImprovementSuggestionResponseParser(AI生成テキストの表示用
 * 切り詰め)とBrandWheelImprovementFocusComposer(競合引用の表示用切り詰め)が
 * 共有する。BrandWheelImprovementSuggestionInputFactoryのcapText/capEvidence
 * (AIへの入力のトークン量抑制用、読者の目に触れない)は対象外 ―― 文の
 * 途中で切れても表示上の問題にならないため、従来どおりの単純切り詰めを維持する。
 */
class BrandWheelTextTruncator
{
    public static function truncateAtSentenceBoundary(string $text, int $maxChars): string
    {
        if (mb_strlen($text) <= $maxChars) {
            return $text;
        }

        $truncated = mb_substr($text, 0, $maxChars);
        $lastPeriodPos = mb_strrpos($truncated, '。');

        if ($lastPeriodPos !== false) {
            return mb_substr($truncated, 0, $lastPeriodPos + 1);
        }

        return $truncated.'…';
    }
}
