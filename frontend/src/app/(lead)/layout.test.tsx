import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import LeadLayout from "./layout";

/**
 * 2026-08-18: リードブランド適用の確認。既存の固定<h1>「無料ホームページ
 * 診断」は各ページ固有の見出しに置き換わるため削除され、代わりにロゴ+
 * 英字タグラインの共通ヘッダーになる。globals.cssの:rootは変更していない
 * ため、ここでは.lead-brandスコープが正しくルート要素に付くことのみを
 * 確認する(実際の配色比較はブラウザでの目視確認で行う)。
 */
describe("LeadLayout", () => {
  it("shows the logo and tagline header instead of the old fixed heading", () => {
    render(
      <LeadLayout>
        <p>children</p>
      </LeadLayout>,
    );

    expect(screen.getByAltText("LEGGENDA")).toBeInTheDocument();
    expect(screen.getByText("RECRUITING BRAND DIAGNOSIS")).toBeInTheDocument();
    expect(screen.queryByText("無料ホームページ診断")).not.toBeInTheDocument();
  });

  it("applies the lead-brand scope class to the root element", () => {
    const { container } = render(
      <LeadLayout>
        <p>children</p>
      </LeadLayout>,
    );

    expect(container.querySelector(".lead-brand")).not.toBeNull();
  });
});
