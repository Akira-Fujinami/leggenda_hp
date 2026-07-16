import { describe, expect, it, vi } from "vitest";
import { render, screen } from "@testing-library/react";
import userEvent from "@testing-library/user-event";
import { ProjectForm } from "@/features/projects/project-form";

describe("ProjectForm", () => {
  it("shows a validation error when the name is empty", async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();

    render(
      <ProjectForm submitLabel="作成する" pendingLabel="作成中…" isPending={false} error={null} onSubmit={onSubmit} />,
    );

    await user.click(screen.getByRole("button", { name: "作成する" }));

    expect(await screen.findByText("プロジェクト名は必須です。")).toBeInTheDocument();
    expect(onSubmit).not.toHaveBeenCalled();
  });

  it("submits with a valid name", async () => {
    const onSubmit = vi.fn();
    const user = userEvent.setup();

    render(
      <ProjectForm submitLabel="作成する" pendingLabel="作成中…" isPending={false} error={null} onSubmit={onSubmit} />,
    );

    await user.type(screen.getByLabelText("プロジェクト名"), "自社サイトと競合比較");
    await user.click(screen.getByRole("button", { name: "作成する" }));

    expect(onSubmit).toHaveBeenCalled();
  });
});
