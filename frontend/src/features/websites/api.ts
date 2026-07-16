import { api, ApiEnvelope } from "@/lib/api-client";
import type { Website } from "@/types/website";

export interface WebsiteInput {
  name: string;
  url: string;
  is_primary?: boolean;
}

export const websitesApi = {
  create: (projectId: number, input: WebsiteInput) =>
    api.post<ApiEnvelope<Website>>(`/api/projects/${projectId}/websites`, input),
  update: (websiteId: number, input: Partial<WebsiteInput>) =>
    api.patch<ApiEnvelope<Website>>(`/api/websites/${websiteId}`, input),
  delete: (websiteId: number) => api.delete<ApiEnvelope<unknown>>(`/api/websites/${websiteId}`),
};
