"use client";

import { useEffect } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { Textarea } from "@/components/ui/textarea";
import { ApiError } from "@/lib/api-client";
import type { Project } from "@/types/project";

const projectSchema = z.object({
  name: z.string().min(1, "プロジェクト名は必須です。").max(255, "プロジェクト名は255文字以内で入力してください。"),
  description: z.string().max(2000, "説明は2000文字以内で入力してください。").optional(),
  industry: z.string().max(255).optional(),
  purpose: z.string().max(255).optional(),
});

export type ProjectFormValues = z.infer<typeof projectSchema>;

interface ProjectFormProps {
  defaultValues?: Partial<ProjectFormValues>;
  submitLabel: string;
  pendingLabel: string;
  isPending: boolean;
  error: unknown;
  onSubmit: (values: ProjectFormValues) => void;
}

export function ProjectForm({
  defaultValues,
  submitLabel,
  pendingLabel,
  isPending,
  error,
  onSubmit,
}: ProjectFormProps) {
  const {
    register,
    handleSubmit,
    setError,
    formState: { errors },
  } = useForm<ProjectFormValues>({
    resolver: zodResolver(projectSchema),
    defaultValues: {
      name: defaultValues?.name ?? "",
      description: defaultValues?.description ?? "",
      industry: defaultValues?.industry ?? "",
      purpose: defaultValues?.purpose ?? "",
    },
  });

  const handleFormSubmit = handleSubmit((values) => {
    onSubmit(values);
  });

  const generalError = error instanceof ApiError && error.errorCode !== "VALIDATION_ERROR" ? error.message : null;

  useEffect(() => {
    if (error instanceof ApiError && error.errorCode === "VALIDATION_ERROR") {
      for (const [field, messages] of Object.entries(error.errors)) {
        if (field in defaultFieldSet) {
          setError(field as keyof ProjectFormValues, { message: messages[0] });
        }
      }
    }
  }, [error, setError]);

  return (
    <form onSubmit={handleFormSubmit} className="space-y-4" noValidate>
      {generalError && (
        <Alert variant="destructive">
          <AlertDescription>{generalError}</AlertDescription>
        </Alert>
      )}

      <div className="space-y-2">
        <Label htmlFor="name">プロジェクト名</Label>
        <Input id="name" {...register("name")} />
        {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="description">説明</Label>
        <Textarea id="description" rows={3} {...register("description")} />
        {errors.description && <p className="text-sm text-destructive">{errors.description.message}</p>}
      </div>

      <div className="grid grid-cols-1 gap-4 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="industry">業種</Label>
          <Input id="industry" {...register("industry")} />
          {errors.industry && <p className="text-sm text-destructive">{errors.industry.message}</p>}
        </div>
        <div className="space-y-2">
          <Label htmlFor="purpose">比較目的</Label>
          <Input id="purpose" {...register("purpose")} />
          {errors.purpose && <p className="text-sm text-destructive">{errors.purpose.message}</p>}
        </div>
      </div>

      <Button type="submit" disabled={isPending}>
        {isPending ? pendingLabel : submitLabel}
      </Button>
    </form>
  );
}

const defaultFieldSet: Record<keyof ProjectFormValues, true> = {
  name: true,
  description: true,
  industry: true,
  purpose: true,
};

export type { Project };
