import { CategoryScoreCard } from "@/features/analysis/results/category-score-card";
import { categoryKeyToSectionId } from "@/features/analysis/results/section-config";
import type { CategoryScore, MetricEvaluation } from "@/types/analysis";

export function CategoryOverviewGrid({
  categories,
  metrics,
  onViewDetails,
}: {
  categories: CategoryScore[];
  metrics: MetricEvaluation[];
  onViewDetails: (sectionId: string) => void;
}) {
  return (
    <div className="grid gap-4 sm:grid-cols-2 lg:grid-cols-3">
      {categories.map((category) => {
        const sectionId = categoryKeyToSectionId(category.key);
        return (
          <CategoryScoreCard
            key={category.key}
            category={category}
            metrics={metrics}
            onViewDetails={sectionId ? () => onViewDetails(sectionId) : undefined}
          />
        );
      })}
    </div>
  );
}
