import { LeadOnboardingForm } from "@/features/lead/lead-onboarding-form";

export default function LeadStartPage() {
  return (
    <div className="space-y-4">
      <p className="text-sm text-muted-foreground">
        自社サイトと競合サイトを無料で比較診断できます。以下の情報をご入力のうえお申し込みください。
      </p>
      <LeadOnboardingForm />
    </div>
  );
}
