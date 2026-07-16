"use client";

import { useEffect } from "react";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { useCreateWebsite } from "@/features/websites/hooks";

const websiteSchema = z.object({
  name: z.string().min(1, "サイト名は必須です。").max(255),
  url: z.string().min(1, "URLは必須です。").max(2048),
  is_primary: z.boolean().optional(),
});

type WebsiteFormValues = z.infer<typeof websiteSchema>;

export function WebsiteForm({ projectId, disabled }: { projectId: number; disabled: boolean }) {
  const createWebsite = useCreateWebsite(projectId);
  const {
    register,
    handleSubmit,
    reset,
    setError,
    formState: { errors },
  } = useForm<WebsiteFormValues>({
    resolver: zodResolver(websiteSchema),
    defaultValues: { name: "", url: "", is_primary: false },
  });

  useEffect(() => {
    if (createWebsite.error instanceof ApiError && createWebsite.error.errorCode === "VALIDATION_ERROR") {
      for (const [field, messages] of Object.entries(createWebsite.error.errors)) {
        if (field === "name" || field === "url" || field === "is_primary") {
          setError(field, { message: messages[0] });
        }
      }
    }
  }, [createWebsite.error, setError]);

  const onSubmit = (values: WebsiteFormValues) => {
    createWebsite.mutate(values, {
      onSuccess: () => reset(),
    });
  };

  const generalError =
    createWebsite.error instanceof ApiError && createWebsite.error.errorCode !== "VALIDATION_ERROR"
      ? createWebsite.error.message
      : null;

  if (disabled) {
    return (
      <p className="rounded-md border border-dashed p-4 text-sm text-muted-foreground">
        登録できるサイトは最大5件です。上限に達しています。
      </p>
    );
  }

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-3" noValidate>
      {generalError && (
        <Alert variant="destructive">
          <AlertDescription>{generalError}</AlertDescription>
        </Alert>
      )}

      <div className="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div className="space-y-2">
          <Label htmlFor="website-name">サイト名</Label>
          <Input id="website-name" {...register("name")} />
          {errors.name && <p className="text-sm text-destructive">{errors.name.message}</p>}
        </div>
        <div className="space-y-2">
          <Label htmlFor="website-url">URL</Label>
          <Input id="website-url" placeholder="example.com" {...register("url")} />
          {errors.url && <p className="text-sm text-destructive">{errors.url.message}</p>}
        </div>
      </div>

      <div className="flex items-center gap-2">
        <input id="is_primary" type="checkbox" className="h-4 w-4 rounded border-input" {...register("is_primary")} />
        <Label htmlFor="is_primary" className="font-normal">
          自社サイトとして登録する
        </Label>
      </div>

      <Button type="submit" disabled={createWebsite.isPending}>
        {createWebsite.isPending ? "登録中…" : "サイトを追加"}
      </Button>
    </form>
  );
}
