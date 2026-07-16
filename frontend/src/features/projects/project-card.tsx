import Link from "next/link";
import { Badge } from "@/components/ui/badge";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import type { Project } from "@/types/project";

function formatDate(value: string): string {
  return new Intl.DateTimeFormat("ja-JP", { dateStyle: "medium", timeStyle: "short" }).format(new Date(value));
}

export function ProjectCard({ project }: { project: Project }) {
  return (
    <Link href={`/projects/${project.id}`}>
      <Card className="h-full transition-colors hover:border-primary/50">
        <CardHeader>
          <CardTitle className="flex items-start justify-between gap-2">
            <span className="line-clamp-1">{project.name}</span>
            <Badge variant="secondary" className="shrink-0">
              サイト {project.websites_count ?? 0}件
            </Badge>
          </CardTitle>
        </CardHeader>
        <CardContent className="space-y-2 text-sm text-muted-foreground">
          {project.description && <p className="line-clamp-2">{project.description}</p>}
          <div className="flex flex-wrap gap-x-4 gap-y-1">
            {project.industry && <span>業種: {project.industry}</span>}
            {project.purpose && <span>目的: {project.purpose}</span>}
          </div>
          <p className="text-xs">更新日時: {formatDate(project.updated_at)}</p>
        </CardContent>
      </Card>
    </Link>
  );
}
