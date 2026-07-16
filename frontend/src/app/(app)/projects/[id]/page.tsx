"use client";

import { use, useState } from "react";
import Link from "next/link";
import { useRouter } from "next/navigation";
import { Pencil, Trash2 } from "lucide-react";
import {
  AlertDialog,
  AlertDialogAction,
  AlertDialogCancel,
  AlertDialogContent,
  AlertDialogDescription,
  AlertDialogFooter,
  AlertDialogHeader,
  AlertDialogTitle,
  AlertDialogTrigger,
} from "@/components/ui/alert-dialog";
import { Button } from "@/components/ui/button";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useDeleteProject, useProject } from "@/features/projects/hooks";
import { WebsiteForm } from "@/features/websites/website-form";
import { WebsiteTable } from "@/features/websites/website-table";

const MAX_WEBSITES = 5;

export default function ProjectDetailPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const projectId = Number(id);
  const router = useRouter();
  const { data, isLoading, isError } = useProject(projectId);
  const deleteProject = useDeleteProject();
  const [isDeleting, setIsDeleting] = useState(false);

  const handleDelete = () => {
    setIsDeleting(true);
    deleteProject.mutate(projectId, {
      onSuccess: () => router.replace("/dashboard"),
      onSettled: () => setIsDeleting(false),
    });
  };

  if (isLoading) {
    return (
      <div className="space-y-4">
        <Skeleton className="h-10 w-1/2" />
        <Skeleton className="h-40" />
      </div>
    );
  }

  if (isError || !data) {
    return (
      <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
        プロジェクトの読み込みに失敗しました。
      </p>
    );
  }

  const project = data.data;
  const websiteCount = project.websites.length;
  const limitReached = websiteCount >= MAX_WEBSITES;

  return (
    <div className="space-y-6">
      <div className="flex items-start justify-between gap-4">
        <div>
          <h1 className="text-xl font-semibold tracking-tight">{project.name}</h1>
          {project.description && <p className="mt-1 text-sm text-muted-foreground">{project.description}</p>}
          <div className="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-xs text-muted-foreground">
            {project.industry && <span>業種: {project.industry}</span>}
            {project.purpose && <span>比較目的: {project.purpose}</span>}
          </div>
        </div>
        <div className="flex shrink-0 gap-2">
          <Button variant="outline" size="sm" render={<Link href={`/projects/${project.id}/edit`} />} nativeButton={false}>
            <Pencil className="size-4" />
            編集
          </Button>
          <AlertDialog>
            <AlertDialogTrigger
              render={<Button variant="outline" size="sm" className="text-destructive hover:text-destructive" />}
            >
              <Trash2 className="size-4" />
              削除
            </AlertDialogTrigger>
            <AlertDialogContent>
              <AlertDialogHeader>
                <AlertDialogTitle>このプロジェクトを削除しますか？</AlertDialogTitle>
                <AlertDialogDescription>
                  登録されているWebサイトも削除されます。この操作は元に戻せません。
                </AlertDialogDescription>
              </AlertDialogHeader>
              <AlertDialogFooter>
                <AlertDialogCancel>キャンセル</AlertDialogCancel>
                <AlertDialogAction
                  className="bg-destructive text-destructive-foreground hover:bg-destructive/90"
                  disabled={isDeleting}
                  onClick={handleDelete}
                >
                  {isDeleting ? "削除中…" : "削除する"}
                </AlertDialogAction>
              </AlertDialogFooter>
            </AlertDialogContent>
          </AlertDialog>
        </div>
      </div>

      <Card>
        <CardHeader className="flex-row items-center justify-between space-y-0">
          <CardTitle>登録サイト（{websiteCount} / {MAX_WEBSITES}件）</CardTitle>
          <Button disabled title="分析機能は準備中です">
            分析を開始する
          </Button>
        </CardHeader>
        <CardContent className="space-y-6">
          {websiteCount === 0 ? (
            <p className="rounded-md border border-dashed p-6 text-center text-sm text-muted-foreground">
              サイトを登録して分析を開始しましょう。
            </p>
          ) : (
            <WebsiteTable projectId={project.id} websites={project.websites} />
          )}

          <WebsiteForm projectId={project.id} disabled={limitReached} />
        </CardContent>
      </Card>
    </div>
  );
}
