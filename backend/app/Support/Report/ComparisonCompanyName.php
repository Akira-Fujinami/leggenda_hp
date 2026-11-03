<?php

namespace App\Support\Report;

use App\Enums\AnalysisKind;
use App\Models\Analysis;
use App\Models\Website;

/**
 * 依頼DL: 比較の資料に出す会社名。
 *  - 自社: 管理者の比較(AdminComparison)では、比較を始めたときに入力した自社名(自社のWebsite.name)を使う。
 *    入力が無い・既知のプレースホルダ(「自社サイト」)のとき、または比較でないときは、従来どおり紐づいた企業(LeadCompany)の名前。
 *    (同じドメインの企業が別の名前で登録済みでも、資料には入力した名前を出すため。企業の紐づけそのものは変えない)
 *  - 競合: Website.name
 */
class ComparisonCompanyName
{
    public const SELF_PLACEHOLDERS = ['自社サイト'];

    public static function self(Analysis $analysis): string
    {
        if ($analysis->kind === AnalysisKind::AdminComparison) {
            $analysis->loadMissing('project.websites');
            $primary = $analysis->project?->websites?->first(fn (Website $w) => (bool) $w->is_primary);
            $name = trim((string) ($primary?->name ?? ''));
            if ($name !== '' && ! in_array($name, self::SELF_PLACEHOLDERS, true)) {
                return $name;
            }
        }

        return (string) ($analysis->project?->leadCompany?->company_name ?? 'お客様');
    }

    public static function forWebsite(Analysis $analysis, ?Website $website): string
    {
        if ($website !== null && (bool) $website->is_primary) {
            return self::self($analysis);
        }

        return (string) ($website?->name ?? '');
    }
}
