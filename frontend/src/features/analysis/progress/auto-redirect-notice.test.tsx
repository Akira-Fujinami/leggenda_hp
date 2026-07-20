import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { AutoRedirectNotice } from "@/features/analysis/progress/auto-redirect-notice";

describe("AutoRedirectNotice", () => {
  it("shows the completed announcement and both action buttons while pending", () => {
    const onRedirectNow = vi.fn();
    const onCancel = vi.fn();

    render(
      <AutoRedirectNotice status="completed" pending cancelled={false} onRedirectNow={onRedirectNow} onCancel={onCancel} />
    );

    expect(screen.getByText("分析が完了しました。結果画面へ移動します。")).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "今すぐ結果を見る" })).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "自動遷移を停止" })).toBeInTheDocument();
  });

  it("shows the partial announcement", () => {
    render(
      <AutoRedirectNotice status="partial" pending cancelled={false} onRedirectNow={vi.fn()} onCancel={vi.fn()} />
    );

    expect(
      screen.getByText("分析処理が完了しました。一部取得できなかった項目があります。取得済みの結果を表示します。")
    ).toBeInTheDocument();
  });

  it("calls onRedirectNow when clicking '今すぐ結果を見る'", async () => {
    const user = userEvent.setup();
    const onRedirectNow = vi.fn();

    render(
      <AutoRedirectNotice status="completed" pending cancelled={false} onRedirectNow={onRedirectNow} onCancel={vi.fn()} />
    );
    await user.click(screen.getByRole("button", { name: "今すぐ結果を見る" }));

    expect(onRedirectNow).toHaveBeenCalledTimes(1);
  });

  it("calls onCancel and stops offering the cancel button once cancelled", async () => {
    const user = userEvent.setup();
    const onCancel = vi.fn();

    render(
      <AutoRedirectNotice status="partial" pending cancelled={false} onRedirectNow={vi.fn()} onCancel={onCancel} />
    );
    await user.click(screen.getByRole("button", { name: "自動遷移を停止" }));

    expect(onCancel).toHaveBeenCalledTimes(1);
  });

  it("still offers manual navigation after the auto-redirect was cancelled", () => {
    render(
      <AutoRedirectNotice status="partial" pending={false} cancelled onRedirectNow={vi.fn()} onCancel={vi.fn()} />
    );

    expect(screen.getByText(/自動遷移を停止しました/)).toBeInTheDocument();
    expect(screen.getByRole("button", { name: "今すぐ結果を見る" })).toBeInTheDocument();
    expect(screen.queryByRole("button", { name: "自動遷移を停止" })).not.toBeInTheDocument();
  });

  it("renders nothing when neither pending nor cancelled", () => {
    const { container } = render(
      <AutoRedirectNotice status="completed" pending={false} cancelled={false} onRedirectNow={vi.fn()} onCancel={vi.fn()} />
    );

    expect(container).toBeEmptyDOMElement();
  });
});
