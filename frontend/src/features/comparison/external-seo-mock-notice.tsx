import { FlaskConical } from "lucide-react";
import { Alert, AlertDescription, AlertTitle } from "@/components/ui/alert";
import type { ExternalSeoInfo, RankingEntry } from "@/types/comparison";

/**
 * 外部SEO(Semrush)のMockデータ警告を、サイトごとに繰り返さずAnalysis単位で
 * 一度だけ表示する。全サイトMockの場合は1件の統合メッセージ、実データと
 * 混在する場合のみMockのサイトを個別に列挙する。
 */
export function ExternalSeoMockNotice({ ranking, externalSeo }: { ranking: RankingEntry[]; externalSeo: ExternalSeoInfo[] }) {
  const withData = externalSeo.filter((e) => e.status === "success");
  if (withData.length === 0) return null;

  const mockEntries = withData.filter((e) => e.is_mock);
  if (mockEntries.length === 0) return null;

  const nameOf = (websiteAnalysisId: number) =>
    ranking.find((r) => r.website_analysis_id === websiteAnalysisId)?.website_name ?? `サイト #${websiteAnalysisId}`;

  const allMock = mockEntries.length === withData.length;

  if (allMock) {
    return (
      <Alert>
        <FlaskConical />
        <AlertTitle>外部SEOデータについて</AlertTitle>
        <AlertDescription>
          {mockEntries.map((e) => nameOf(e.website_analysis_id)).join("・")}ともSemrush実データを取得できていません。開発用デモデータは総合スコア・順位・強み弱みに使用していません。
        </AlertDescription>
      </Alert>
    );
  }

  return (
    <div className="space-y-2">
      {mockEntries.map((e) => (
        <Alert key={e.website_analysis_id}>
          <FlaskConical />
          <AlertTitle>{nameOf(e.website_analysis_id)}の外部SEOデータについて</AlertTitle>
          <AlertDescription>開発用デモデータを使用しています(総合スコア・順位・強み弱みには未反映です)。</AlertDescription>
        </Alert>
      ))}
    </div>
  );
}
