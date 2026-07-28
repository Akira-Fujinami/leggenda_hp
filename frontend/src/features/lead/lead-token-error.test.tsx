import { describe, expect, it } from "vitest";
import { render, screen } from "@testing-library/react";
import { isLeadTokenError, LeadTokenError } from "@/features/lead/lead-token-error";
import { ApiError, ApiNetworkError } from "@/lib/api-client";

describe("LeadTokenError", () => {
  it("shows the unified message and a link back to the onboarding form", () => {
    render(<LeadTokenError />);

    expect(screen.getByText("この診断URLは利用できません。お手数ですが、もう一度お申し込みください。")).toBeInTheDocument();
    expect(screen.getByRole("link", { name: "最初からやり直す" })).toHaveAttribute("href", "/lead/start");
  });
});

describe("isLeadTokenError", () => {
  it("is true for a missing-token ApiError", () => {
    expect(isLeadTokenError(new ApiError(401, "x", {}, "LEAD_TOKEN_MISSING", null, "/x"))).toBe(true);
  });

  it("is true for an invalid/expired/used-up-looking token ApiError, without distinguishing why", () => {
    expect(isLeadTokenError(new ApiError(401, "x", {}, "LEAD_TOKEN_INVALID", null, "/x"))).toBe(true);
  });

  it("is false for unrelated error codes such as congestion or quota", () => {
    expect(isLeadTokenError(new ApiError(503, "x", {}, "LEAD_ANALYZER_BUSY", null, "/x"))).toBe(false);
    expect(isLeadTokenError(new ApiError(403, "x", {}, "LEAD_ANALYSIS_QUOTA_EXCEEDED", null, "/x"))).toBe(false);
  });

  it("is false for a network error, null, or undefined", () => {
    expect(isLeadTokenError(new ApiNetworkError("/x"))).toBe(false);
    expect(isLeadTokenError(null)).toBe(false);
    expect(isLeadTokenError(undefined)).toBe(false);
  });
});
