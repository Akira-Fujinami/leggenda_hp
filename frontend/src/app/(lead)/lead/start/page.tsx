import { LeadOnboardingForm } from "@/features/lead/lead-onboarding-form";

export default function LeadStartPage() {
  return (
    <div className="space-y-6">
      <div className="space-y-3 text-center">
        <p className="lead-eyebrow">無料診断</p>
        <h1 className="lead-heading">採用サイトは、候補者に何を伝えていますか。</h1>
        <div className="lead-rule" />
        <p className="text-sm text-muted-foreground">
          貴社の採用サイトを24の観点から読み解き、いま伝わっていることと、伝わっていないことを整理します。
          所要時間は約2分。費用はかかりません。
        </p>
      </div>
      <div className="space-y-4">
        <h2 className="lead-card-heading">お客さま情報のご入力</h2>
        <LeadOnboardingForm />
      </div>
    </div>
  );
}
