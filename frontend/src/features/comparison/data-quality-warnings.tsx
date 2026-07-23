import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import type { DataQuality, RankingEntry } from "@/types/comparison";

const WARNING_LABELS: Record<string, string> = {
  coverage_below_70: "測定カバー率が70%未満です。スコアが実態より低く見える場合があります。",
  confidence_below_70: "測定データの信頼度が70%未満です。",
  contains_mock_data: "デモデータ(モック)が含まれています。実データではありません。",
  lighthouse_failed: "Lighthouse計測に失敗しました。パフォーマンス系の項目が未取得です。",
  partial_html_fetch: "ページ取得が一部失敗しており、結果が不完全な可能性があります。",
};

// Mockデータの警告は`ExternalSeoMockNotice`がAnalysis単位で一度だけ表示するため、
// サイトごとの品質警告からは除外し、同じ内容の重複表示を避ける。
function nonMockWarnings(warnings: string[]): string[] {
  return warnings.filter((w) => w !== "contains_mock_data");
}

export function DataQualityWarnings({ ranking, dataQuality }: { ranking: RankingEntry[]; dataQuality: DataQuality[] }) {
  const withWarnings = dataQuality
    .map((dq) => ({ ...dq, warnings: nonMockWarnings(dq.warnings) }))
    .filter((dq) => dq.warnings.length > 0);
  if (withWarnings.length === 0) return null;

  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  return (
    <div className="space-y-2">
      {withWarnings.map((dq) => (
        <Alert key={dq.website_analysis_id}>
          <AlertTitle>{nameOf(dq.website_analysis_id)}のデータ品質について</AlertTitle>
          <AlertDescription>
            <ul className="list-inside list-disc space-y-0.5">
              {dq.warnings.map((warning) => (
                <li key={warning}>{WARNING_LABELS[warning] ?? warning}</li>
              ))}
            </ul>
          </AlertDescription>
        </Alert>
      ))}
    </div>
  );
}
