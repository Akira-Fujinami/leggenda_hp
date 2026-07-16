"use client";

import { useState } from "react";
import Link from "next/link";
import { Plus } from "lucide-react";
import { Button } from "@/components/ui/button";
import { Skeleton } from "@/components/ui/skeleton";
import { useProjects } from "@/features/projects/hooks";
import { ProjectCard } from "@/features/projects/project-card";

export default function DashboardPage() {
  const [page, setPage] = useState(1);
  const { data, isLoading, isError } = useProjects(page);

  const projects = data?.data ?? [];
  const pagination = data?.meta.pagination;

  return (
    <div className="space-y-6">
      <div className="flex items-center justify-between">
        <h1 className="text-xl font-semibold tracking-tight">ダッシュボード</h1>
        <Button render={<Link href="/projects/new" />} nativeButton={false}>
          <Plus className="size-4" />
          新規比較プロジェクト作成
        </Button>
      </div>

      {isLoading && (
        <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
          {Array.from({ length: 3 }).map((_, i) => (
            <Skeleton key={i} className="h-40 rounded-lg" />
          ))}
        </div>
      )}

      {isError && (
        <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
          プロジェクトの読み込みに失敗しました。時間をおいて再度お試しください。
        </p>
      )}

      {!isLoading && !isError && projects.length === 0 && (
        <div className="rounded-lg border border-dashed p-12 text-center">
          <p className="text-sm text-muted-foreground">
            まだ比較プロジェクトがありません。サイトを登録して分析を開始しましょう。
          </p>
          <Button render={<Link href="/projects/new" />} nativeButton={false} className="mt-4">
            <Plus className="size-4" />
            新規比較プロジェクト作成
          </Button>
        </div>
      )}

      {!isLoading && !isError && projects.length > 0 && (
        <>
          <div className="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
            {projects.map((project) => (
              <ProjectCard key={project.id} project={project} />
            ))}
          </div>

          {pagination && pagination.last_page > 1 && (
            <div className="flex items-center justify-center gap-4 pt-4">
              <Button
                variant="outline"
                size="sm"
                disabled={pagination.current_page <= 1}
                onClick={() => setPage((p) => p - 1)}
              >
                前へ
              </Button>
              <span className="text-sm text-muted-foreground">
                {pagination.current_page} / {pagination.last_page} ページ
              </span>
              <Button
                variant="outline"
                size="sm"
                disabled={pagination.current_page >= pagination.last_page}
                onClick={() => setPage((p) => p + 1)}
              >
                次へ
              </Button>
            </div>
          )}
        </>
      )}
    </div>
  );
}
