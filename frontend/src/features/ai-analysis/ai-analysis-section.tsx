import { AiAnalysisPanel } from "@/features/ai-analysis/ai-analysis-panel";
import type { RankingEntry } from "@/types/comparison";

export function AiAnalysisSection({ ranking }: { ranking: RankingEntry[] }) {
  const orderedSites = [...ranking].sort((a, b) => a.rank - b.rank);

  return (
    <div className="space-y-4">
      <h2 className="text-base font-semibold tracking-tight">サイト別 AI参考分析</h2>
      <div className="grid gap-4 lg:grid-cols-2">
        {orderedSites.map((site) => (
          <div key={site.website_analysis_id} className="space-y-2">
            <p className="text-sm font-medium text-muted-foreground">
              {site.website_name ?? `サイト #${site.website_id}`}
            </p>
            <AiAnalysisPanel websiteAnalysisId={site.website_analysis_id} />
          </div>
        ))}
      </div>
    </div>
  );
}
