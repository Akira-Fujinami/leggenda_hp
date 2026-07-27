<?php

namespace App\Services\Report;

use App\Enums\RecommendationEffort;
use App\Enums\RecommendationImpact;
use App\Enums\RecommendationPriority;

/**
 * RecommendationPriority/Impact/Effortの4/3/3値を、レポートに表示する
 * 日本語ラベルへ変換する。RecommendationPriority::Criticalを含む4段階すべてを
 * 網羅する(Phase 1の簡易結果画面は現状high/medium/lowの3段階しか
 * ラベル化していないが、レポートでは緊急案件を含む全件を正しく表示する)。
 */
class RecommendationLabelFormatter
{
    public function priorityLabel(RecommendationPriority $priority): string
    {
        return match ($priority) {
            RecommendationPriority::Critical => '緊急',
            RecommendationPriority::High => '高',
            RecommendationPriority::Medium => '中',
            RecommendationPriority::Low => '低',
        };
    }

    public function impactLabel(RecommendationImpact $impact): string
    {
        return match ($impact) {
            RecommendationImpact::High => '高',
            RecommendationImpact::Medium => '中',
            RecommendationImpact::Low => '低',
        };
    }

    public function effortLabel(RecommendationEffort $effort): string
    {
        return match ($effort) {
            RecommendationEffort::Small => '小',
            RecommendationEffort::Medium => '中',
            RecommendationEffort::Large => '大',
        };
    }
}
