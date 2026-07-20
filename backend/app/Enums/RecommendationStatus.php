<?php

namespace App\Enums;

/**
 * 履歴比較(recommendation_added/resolved/continued)の判定に使う
 * Recommendationのライフサイクル状態。
 */
enum RecommendationStatus: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case Dismissed = 'dismissed';
}
