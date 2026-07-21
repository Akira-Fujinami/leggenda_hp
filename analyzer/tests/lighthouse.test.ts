import { describe, expect, it } from "vitest";
import { buildLighthouseMetadata } from "../src/lighthouse.js";

describe("buildLighthouseMetadata", () => {
  it("reports run_count=1 and surfaces Lighthouse's own reported settings", () => {
    const lhr = {
      configSettings: { formFactor: "mobile", throttlingMethod: "simulate" },
      fetchTime: "2026-07-21T00:00:00.000Z",
      lighthouseVersion: "12.2.1",
    };

    const metadata = buildLighthouseMetadata(lhr, 60_000);

    expect(metadata.run_count).toBe(1);
    expect(metadata.device_profile).toBe("mobile");
    expect(metadata.throttling_method).toBe("simulate");
    expect(metadata.measured_at).toBe("2026-07-21T00:00:00.000Z");
    expect(metadata.lighthouse_version).toBe("12.2.1");
    expect(metadata.timeout_ms).toBe(60_000);
  });

  it("does not fabricate settings that Lighthouse did not report", () => {
    const metadata = buildLighthouseMetadata({}, 30_000);

    expect(metadata.run_count).toBe(1);
    expect(metadata.device_profile).toBeNull();
    expect(metadata.throttling_method).toBeNull();
    expect(metadata.measured_at).toBeNull();
    expect(metadata.lighthouse_version).toBeNull();
    expect(metadata.timeout_ms).toBe(30_000);
  });
});
