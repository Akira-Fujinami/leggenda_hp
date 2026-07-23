import type { CategorySiteScore } from "@/types/comparison";

/**
 * 採点エンジンはMock/not_applicable等で採点対象の指標が1件も無いカテゴリを
 * max_available_score=0として返す(score-card.tsxの isUnavailable と同じ考え方)。
 * この場合は score/configured_max_score のような分数表示ではなく「評価不可」を表示する。
 */
export function isCategoryUnavailable(siteScore: CategorySiteScore | undefined): boolean {
  return !siteScore || siteScore.max_available_score <= 0;
}
