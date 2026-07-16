"use client";

import { useRouter } from "next/navigation";
import { Card, CardContent, CardHeader, CardTitle } from "@/components/ui/card";
import { ProjectForm, ProjectFormValues } from "@/features/projects/project-form";
import { useCreateProject } from "@/features/projects/hooks";

export default function NewProjectPage() {
  const router = useRouter();
  const createProject = useCreateProject();

  const handleSubmit = (values: ProjectFormValues) => {
    createProject.mutate(values, {
      onSuccess: (res) => router.push(`/projects/${res.data.id}`),
    });
  };

  return (
    <div className="mx-auto max-w-xl">
      <Card>
        <CardHeader>
          <CardTitle>新規比較プロジェクト作成</CardTitle>
        </CardHeader>
        <CardContent>
          <ProjectForm
            submitLabel="作成する"
            pendingLabel="作成中…"
            isPending={createProject.isPending}
            error={createProject.error}
            onSubmit={handleSubmit}
          />
        </CardContent>
      </Card>
    </div>
  );
}
