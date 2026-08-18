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
      {generalError && (
        <Alert variant="destructive">
          <AlertDescription>{generalError}</AlertDescription>
        </Alert>
      )}

      <div className="space-y-2">
        <Label htmlFor="self_url">貴社の採用サイト URL</Label>
        <Input id="self_url" placeholder="https://recruit.example.co.jp/" {...register("self_url")} />
        {errors.self_url && <p className="text-sm text-destructive">{errors.self_url.message}</p>}
        <p className="text-xs text-muted-foreground">採用サイトのトップページをご入力ください。</p>
      </div>

      <div className="space-y-2">
        <Label htmlFor="competitor_url">
          比較したい企業の採用サイト URL<span className="lead-optional">(任意・1件まで)</span>
        </Label>
        <Input id="competitor_url" placeholder="https://recruit.example.com/" {...register("competitor_url")} />
        <p className="text-xs text-muted-foreground">候補者が併願しそうな企業を選ぶと、差が見えやすくなります。</p>
      </div>

      <LeadUrlGuidance />

      <Button type="submit" className="w-full" disabled={start.isPending}>
        {start.isPending ? "診断を開始しています…" : "診断をはじめる"}
      </Button>
    </form>
  );
}

/**
 * 2026-08-18追加: どのURLを入力すればよいかのガイダンス枠(入力欄の下・
 * ボタンの上)。文言は依頼者指定の原文どおり(要約・変更しないこと)。
 * ○はネイビー・×はコーラルの丸で区別する(依頼者指定、ロゴの2色以外の
 * 色は増やさない)。
 */
function LeadUrlGuidance() {
  const items: { mark: "o" | "x"; title: string; body: string }[] = [
    {
      mark: "o",
      title: "採用サイトのトップページ",
      body: "採用専用サイトをお持ちであれば、そのトップページが最適です。例：recruit.example.co.jp　/　example.co.jp/recruit/",
    },
    {
      mark: "o",
      title: "コーポレートサイトの「採用情報」ページ",
      body: "専用サイトが無い場合は、こちらでも診断できます。新卒・中途のいずれかに特化したページでも構いません。",
    },
    {
      mark: "x",
      title: "求人媒体の掲載ページ",
      body: "リクナビ・マイナビ・doda・Indeed などの掲載ページは、媒体の書式を診断することになり、貴社ご自身の発信を評価できません。",
    },
    {
      mark: "x",
      title: "求人一覧だけのページ・ログインが必要なページ",
      body: "募集要項の一覧のみのページや、会員登録・ログインを求められるページは、読み取れる情報がごく限られます。",
    },
  ];

  return (
    <div className="space-y-3 border-t pt-4">
      <p className="lead-card-heading text-sm">どのURLを入力すればよいか</p>
      <ul className="space-y-2.5">
        {items.map((item) => (
          <li key={item.title} className="flex gap-2.5">
            <span
              aria-hidden="true"
              className="mt-0.5 flex size-4 shrink-0 items-center justify-center rounded-full text-[10px] font-bold text-white"
              style={{ backgroundColor: item.mark === "o" ? "var(--lead-navy)" : "var(--lead-coral)" }}
            >
              {item.mark === "o" ? "○" : "×"}
            </span>
            <span>
              <span className="block text-sm font-bold">{item.title}</span>
              <span className="text-xs text-muted-foreground">{item.body}</span>
            </span>
          </li>
        ))}
      </ul>
    </div>
  );
}
