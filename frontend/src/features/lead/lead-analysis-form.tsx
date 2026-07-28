"use client";

import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { useStartLeadAnalysis } from "@/features/lead/hooks";
import { isLeadTokenError, LeadTokenError } from "@/features/lead/lead-token-error";

const analysisSchema = z.object({
  self_url: z.string().min(1, "自社サイトのURLを入力してください。").max(2048),
  competitor_url: z.string().max(2048).optional().or(z.literal("")),
});

type AnalysisFormValues = z.infer<typeof analysisSchema>;

export function LeadAnalysisForm({ token, onStarted }: { token: string; onStarted: (analysisId: number) => void }) {
  const start = useStartLeadAnalysis(token);
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<AnalysisFormValues>({
    resolver: zodResolver(analysisSchema),
    defaultValues: { self_url: "", competitor_url: "" },
  });

  const onSubmit = (values: AnalysisFormValues) => {
    start.mutate(
      { self_url: values.self_url, competitor_url: values.competitor_url || undefined },
      { onSuccess: (res) => onStarted(res.data.analysis_id) },
    );
  };

  // トークン照合失敗(未指定/期限切れ/使用済み/該当なし)は理由を出さず、
  // 常に同じ文言+「最初からやり直す」導線に寄せる。それ以外(混雑・利用回数
  // 上限等)は、リード本人の対応可否が異なるため既存の個別メッセージのまま出す。
  if (isLeadTokenError(start.error)) {
    return <LeadTokenError />;
  }

  const generalError = start.error instanceof ApiError ? start.error.message : null;

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
      <p className="text-sm text-muted-foreground">
        自社サイトのURLを入力してください。比較したい競合サイトがあれば、あわせて入力できます(任意・1件まで)。
      </p>

      {generalError && (
        <Alert variant="destructive">
          <AlertDescription>{generalError}</AlertDescription>
        </Alert>
      )}

      <div className="space-y-2">
        <Label htmlFor="self_url">自社サイトのURL</Label>
        <Input id="self_url" placeholder="https://example.com" {...register("self_url")} />
        {errors.self_url && <p className="text-sm text-destructive">{errors.self_url.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="competitor_url">比較したい競合サイトのURL(任意)</Label>
        <Input id="competitor_url" placeholder="https://example.com" {...register("competitor_url")} />
      </div>

      <Button type="submit" className="w-full" disabled={start.isPending}>
        {start.isPending ? "診断を開始しています…" : "診断をはじめる"}
      </Button>
    </form>
  );
}
