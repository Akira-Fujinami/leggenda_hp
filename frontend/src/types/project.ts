import type { Website } from "@/types/website";

export interface Project {
  id: number;
  name: string;
  description: string | null;
  industry: string | null;
  purpose: string | null;
  websites_count?: number;
  created_at: string;
  updated_at: string;
}

export interface ProjectDetail extends Omit<Project, "websites_count"> {
  websites: Website[];
}

export interface Pagination {
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}
