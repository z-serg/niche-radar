// Типы API Niche Radar (зеркалируют backend-ответы).

export interface HistoryPoint {
  ordinal: number;
  exact: number | null;
  broad: number | null;
}

export interface Metrics {
  exact_current?: number | null;
  broad_current?: number | null;
  exact_first: number | null;
  exact_delta: number | null;
  growth_pct: number | null;
  base_avg: number | null;
  late_avg: number | null;
  smoothed_delta: number | null;
  smoothed_growth: number | null;
  consistency: number | null;
  peak_retention: number | null;
  task_score: number | null;
  task_categories: string[];
  priority: number | null;
  null_reasons: Record<string, string>;
  flags?: string[];
  formula_version?: string;
  rules_version?: string;
  metric_run_id?: string;
  priority_components?: {
    g: number | null; a: number | null; c: number | null; v: number | null; t: number | null;
  } | null;
}

export interface KeywordRow {
  keyword_id: number;
  phrase: string;
  exact_current: number | null;
  broad_current: number | null;
  rank_current: number | null;
  rank_previous: number | null;
  rank_delta: number | null;
  rank_previous_note: string | null;
  word_count: number | null;
  history: HistoryPoint[];
  metrics: Metrics;
  flags: string[];
  algorithm_version: string;
}

export interface SearchResponse {
  data: KeywordRow[];
  next_cursor: string | null;
  truncated: boolean;
  hard_limit_reached: boolean;
  dataset_id: string;
  metric_run_id: string | null;
  total_count: number | null;
  applied?: {
    preset?: { key: string; title: string; reliability: string } | null;
    sort: { field: string; direction: string }[];
    limit: number;
  };
}

export interface DatasetListItem {
  id: string;
  title: string;
  file: { name: string; size_bytes: number; sha256: string };
  uploaded_at: string;
  region: string | null;
  row_count: number | null;
  scan_count: number | null;
  coverage_type: string;
  status: string;
  is_active: boolean;
  is_archived: boolean;
  revision: number;
  quality_flags: string[];
  import: {
    status: string;
    stage: string;
    progress: ImportProgress | null;
    error: string | null;
  } | null;
}

export interface ImportProgress {
  stage?: string;
  pct?: number;
  rows?: number;
  bytes?: number;
  total_bytes?: number;
  rows_per_sec?: number;
  eta_sec?: number;
}

export interface ScanInfo {
  id: number;
  ordinal: number;
  source_header: string;
  source_suffix: string | null;
  scan_date: string | null;
}

export interface DatasetDetail {
  id: string;
  title: string;
  status: string;
  coverage_type: string;
  region: string | null;
  frequency_definition: string | null;
  period_description: string | null;
  row_count: number | null;
  scan_count: number | null;
  is_active: boolean;
  revision: number;
  quality_flags: string[];
  mapping: Record<string, unknown> | null;
  scans: ScanInfo[];
  metric_run: {
    id: string;
    status: string;
    algorithm_version: string;
    rules_version: string;
    range: [number, number];
    results_summary: {
      keyword_count?: number;
      preset_counts?: Record<string, number>;
      groups?: Record<string, number>;
    } | null;
  } | null;
}

export interface NicheVersion {
  id: string;
  version: number;
  dataset_id: string;
  metric_run_id: string | null;
  member_count: number;
  complete_member_count: number;
  coverage_pct: number;
  aggregates: NicheAggregates | null;
  created_at: string;
}

export interface NicheAggregates {
  exact_sum_label?: string;
  dynamics?: { ordinal: number; exact_sum: number }[];
  growth_abs?: number | null;
  growth_pct?: number | null;
  growing_share?: number | null;
  leader?: { keyword_id: number; exact_current: number; phrase: string | null } | null;
  leader_share?: number | null;
  median_smoothed_growth?: { value: number | null; positive_base_members: number };
  contribution_leaders?: {
    keyword_id: number;
    phrase: string | null;
    smoothed_delta: number;
    exact_current: number | null;
  }[];
  excluded_incomplete?: number;
}

export interface Niche {
  id: string;
  name: string;
  description: string | null;
  type: 'fixed' | 'rule';
  rule: Record<string, unknown> | null;
  manual_include: number[];
  manual_exclude: number[];
  versions?: NicheVersion[];
  last_version?: number | null;
  manual_include_count?: number;
  manual_exclude_count?: number;
}

export interface Hypothesis {
  id: string;
  title: string;
  niche_id: string | null;
  niche_name?: string | null;
  status: string;
  status_label: string;
  status_reason: string | null;
  audience: string | null;
  problem: string | null;
  current_solution: string | null;
  product_idea: string | null;
  mvp: string | null;
  monetization: string | null;
  notes: string | null;
  research_links: string | null;
  next_experiment: string | null;
  success_criterion: string | null;
  dataset_id: string | null;
  created_at: string;
  updated_at: string;
}

export interface CandidateGroup {
  id: string;
  metric_run_id: string;
  method: 'token' | 'bigram';
  anchor: string;
  member_count: number;
  exact_sum_current: number;
  growing_share: number;
  leader_keyword_id: number;
  leader_share: number;
  dynamics: Record<string, number> | null;
  description?: string;
}

export interface DashboardData {
  dataset: {
    id: string;
    title: string;
    status: string;
    coverage_type: string;
    row_count: number | null;
    scan_count: number | null;
    has_history: boolean;
    quality_flags: string[];
  };
  scans: ScanInfo[];
  metric_run: {
    id: string;
    algorithm_version: string;
    rules_version: string;
    range: [number, number];
    summary: { preset_counts?: Record<string, number> } | null;
  } | null;
  preset_counts?: Record<string, number>;
  growth_leaders?: LeaderRow[];
  fall_leaders?: LeaderRow[];
  new_entrants_count?: number;
  new_entrants_sample?: LeaderRow[];
}

export interface LeaderRow {
  keyword_id: number;
  phrase: string;
  smoothed_delta?: number | null;
  smoothed_growth?: number | null;
  exact_current?: number | null;
  priority?: number | null;
}

export interface ApiError {
  code: string;
  message: string;
  dependencies?: string[];
}
