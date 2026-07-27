"use client";

import { useRouter } from "next/navigation";
import { zodResolver } from "@hookform/resolvers/zod";
import { useForm } from "react-hook-form";
import { z } from "zod";
import { Alert, AlertDescription } from "@/components/ui/alert";
import { Button } from "@/components/ui/button";
import { Input } from "@/components/ui/input";
import { Label } from "@/components/ui/label";
import { ApiError } from "@/lib/api-client";
import { useSubmitLeadOnboarding } from "@/features/lead/hooks";

const onboardingSchema = z.object({
  company_name: z.string().min(1, "会社名を入力してください。").max(255),
  contact_name: z.string().min(1, "ご担当者名を入力してください。").max(255),
  email: z.string().min(1, "メールアドレスを入力してください。").email("メールアドレスの形式が正しくありません。"),
  phone: z.string().max(50).optional().or(z.literal("")),
  industry: z.string().max(255).optional().or(z.literal("")),
  employee_range: z.string().max(100).optional().or(z.literal("")),
  privacy_policy_agreed: z.boolean().refine((v) => v === true, {
    message: "プライバシーポリシーへの同意が必要です。",
  }),
});

type OnboardingFormValues = z.infer<typeof onboardingSchema>;

/**
 * リード獲得フォーム。送信完了後はその場で診断画面(自社URL/競合URL入力)へ
 * 遷移する ―― メール送付は今回のスコープ外(将来追加できる構造として、
 * トークンはURLクエリパラメータで受け渡す設計にしている)。
 */
export function LeadOnboardingForm() {
  const router = useRouter();
  const submit = useSubmitLeadOnboarding();
  const {
    register,
    handleSubmit,
    formState: { errors },
  } = useForm<OnboardingFormValues>({
    resolver: zodResolver(onboardingSchema),
    defaultValues: {
      company_name: "",
      contact_name: "",
      email: "",
      phone: "",
      industry: "",
      employee_range: "",
      privacy_policy_agreed: false,
    },
  });

  const onSubmit = (values: OnboardingFormValues) => {
    submit.mutate(values, {
      onSuccess: (res) => {
        router.replace(`/lead/diagnose?token=${encodeURIComponent(res.data.token)}`);
      },
    });
  };

  const generalError = submit.error instanceof ApiError ? submit.error.message : null;

  return (
    <form onSubmit={handleSubmit(onSubmit)} className="space-y-4" noValidate>
      {generalError && (
        <Alert variant="destructive">
          <AlertDescription>{generalError}</AlertDescription>
        </Alert>
      )}

      <div className="space-y-2">
        <Label htmlFor="company_name">会社名</Label>
        <Input id="company_name" {...register("company_name")} />
        {errors.company_name && <p className="text-sm text-destructive">{errors.company_name.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="contact_name">ご担当者名</Label>
        <Input id="contact_name" {...register("contact_name")} />
        {errors.contact_name && <p className="text-sm text-destructive">{errors.contact_name.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="email">メールアドレス</Label>
        <Input id="email" type="email" {...register("email")} />
        {errors.email && <p className="text-sm text-destructive">{errors.email.message}</p>}
      </div>

      <div className="space-y-2">
        <Label htmlFor="phone">電話番号(任意)</Label>
        <Input id="phone" {...register("phone")} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="industry">業種(任意)</Label>
        <Input id="industry" {...register("industry")} />
      </div>

      <div className="space-y-2">
        <Label htmlFor="employee_range">従業員規模(任意)</Label>
        <Input id="employee_range" placeholder="例: 50-100" {...register("employee_range")} />
      </div>

      <div className="flex items-start gap-2">
        <input id="privacy_policy_agreed" type="checkbox" className="mt-1" {...register("privacy_policy_agreed")} />
        <Label htmlFor="privacy_policy_agreed" className="font-normal">
          プライバシーポリシーに同意します
        </Label>
      </div>
      {errors.privacy_policy_agreed && (
        <p className="text-sm text-destructive">{errors.privacy_policy_agreed.message}</p>
      )}

      <Button type="submit" className="w-full" disabled={submit.isPending}>
        {submit.isPending ? "送信中…" : "無料で診断をはじめる"}
      </Button>
    </form>
  );
}
