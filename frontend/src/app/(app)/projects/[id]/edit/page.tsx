"use client";

import { use } from "react";
import { useRouter } from "next/navigation";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { Skeleton } from "@/components/ui/skeleton";
import { useProject, useUpdateProject } from "@/features/projects/hooks";
import { ProjectForm, ProjectFormValues } from "@/features/projects/project-form";

export default function EditProjectPage({ params }: { params: Promise<{ id: string }> }) {
  const { id } = use(params);
  const projectId = Number(id);
  const router = useRouter();
  const { data, isLoading, isError } = useProject(projectId);
  const updateProject = useUpdateProject(projectId);

  const handleSubmit = (values: ProjectFormValues) => {
    updateProject.mutate(values, {
      onSuccess: () => router.push(`/projects/${projectId}`),
    });
  };

  if (isLoading) {
    return <Skeleton className="h-64" />;
  }

  if (isError || !data) {
    return (
      <p className="rounded-lg border border-destructive/30 bg-destructive/5 p-4 text-sm text-destructive">
        プロジェクトの読み込みに失敗しました。
      </p>
    );
  }

  const project = data.data;

  return (
    <div className="mx-auto max-w-xl">
      <Card>
        <CardHeader>
          <CardTitle>プロジェクトを編集</CardTitle>
        </CardHeader>
        <CardContent>
          <ProjectForm
            defaultValues={{
              name: project.name,
              description: project.description ?? "",
              industry: project.industry ?? "",
              purpose: project.purpose ?? "",
            }}
            submitLabel="更新する"
            pendingLabel="更新中…"
            isPending={updateProject.isPending}
            error={updateProject.error}
            onSubmit={handleSubmit}
          />
        </CardContent>
      </Card>
    </div>
  );
}
