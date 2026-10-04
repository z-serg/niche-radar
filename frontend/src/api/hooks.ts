import { useMutation, useQuery, useQueryClient } from '@tanstack/react-query';
import { api } from './client';
import type {
  CandidateGroup, DashboardData, DatasetDetail, DatasetListItem, Hypothesis,
  KeywordRow, Niche, SearchResponse,
} from './types';

export function useDatasets() {
  return useQuery({
    queryKey: ['datasets'],
    queryFn: async () => (await api.get<{ data: DatasetListItem[] }>('/datasets')).data.data,
    refetchInterval: (query) => {
      const data = query.state.data;
      const busy = data?.some((d) => ['queued', 'validating', 'importing', 'calculating'].includes(d.status));
      return busy ? 3000 : false;
    },
  });
}

export function useDataset(id: string | null) {
  return useQuery({
    queryKey: ['dataset', id],
    queryFn: async () => (await api.get<{ data: DatasetDetail }>(`/datasets/${id}`)).data.data,
    enabled: !!id,
    refetchInterval: (query) => {
      const st = query.state.data?.status;
      return ['queued', 'validating', 'importing', 'calculating'].includes(st ?? '') ? 3000 : false;
    },
  });
}

export function useActiveDatasetId() {
  const { data } = useDatasets();
  return data?.find((d) => d.is_active)?.id ?? data?.find((d) => d.status === 'ready')?.id ?? null;
}

export interface SearchParams {
  dataset_id: string;
  metric_run_id?: string | null;
  filters: Record<string, unknown>;
  sort?: { field: string; direction: string }[];
  limit?: number;
  cursor?: string | null;
}

export function useSearch(params: SearchParams | null) {
  return useQuery({
    queryKey: ['search', params],
    queryFn: async () => (await api.post<SearchResponse>('/keywords/search', params)).data,
    enabled: !!params && !!params.dataset_id,
    placeholderData: (prev) => prev,
  });
}

export function useDashboard(datasetId: string | null | undefined) {
  return useQuery({
    queryKey: ['dashboard', datasetId ?? 'active'],
    queryFn: async () =>
      (await api.get<DashboardData>('/analytics/dashboard', {
        params: datasetId ? { dataset_id: datasetId } : {},
      })).data,
    enabled: datasetId !== undefined,
  });
}

export function useKeyword(keywordId: number | null, datasetId: string | null) {
  return useQuery({
    queryKey: ['keyword', keywordId, datasetId],
    queryFn: async () =>
      (await api.get<{ data: KeywordRow & {
        similar: { method: string; items: { id: number; phrase: string; shared_tokens?: number; similarity?: number }[] }[];
        niches: { id: string; name: string; version: number }[];
        labels: { label: string; origin: string; rule_version: string | null }[];
        notes: { id: number; body: string; created_at: string }[];
        search_text: string;
        char_count: number;
        source_word_count: number | null;
        source_char_count: number | null;
        present_in_dataset: boolean;
      } }>(`/keywords/${keywordId}`, { params: { dataset_id: datasetId } })).data.data,
    enabled: keywordId !== null && !!datasetId,
  });
}

export function useSavedSearches() {
  return useQuery({
    queryKey: ['saved-searches'],
    queryFn: async () => (await api.get<{ data: { id: string; name: string; filters: Record<string, unknown>; report_mode: string; dataset_id: string | null }[] }>('/saved-searches')).data.data,
  });
}

export function useNiches() {
  return useQuery({
    queryKey: ['niches'],
    queryFn: async () => (await api.get<{ data: Niche[] }>('/niches')).data.data,
  });
}

export function useNiche(id: string | null) {
  return useQuery({
    queryKey: ['niche', id],
    queryFn: async () => (await api.get<{ data: Niche }>(`/niches/${id}`)).data.data,
    enabled: !!id,
  });
}

export function useHypotheses(status?: string) {
  return useQuery({
    queryKey: ['hypotheses', status ?? 'all'],
    queryFn: async () =>
      (await api.get<{ data: Hypothesis[] }>('/hypotheses', { params: status ? { status } : {} })).data.data,
  });
}

export function useHypothesis(id: string | null) {
  return useQuery({
    queryKey: ['hypothesis', id],
    queryFn: async () => (await api.get<{ data: Hypothesis & { evidence: { id: number; keyword_id: number; phrase: string; snapshot: Record<string, unknown> }[] } }>(`/hypotheses/${id}`)).data.data,
    enabled: !!id,
  });
}

export function useCandidateGroups(datasetId: string | null, method?: string) {
  return useQuery({
    queryKey: ['candidate-groups', datasetId, method ?? 'all'],
    queryFn: async () =>
      (await api.get<{ data: CandidateGroup[] }>('/candidate-groups', {
        params: { dataset_id: datasetId, ...(method ? { method } : {}) },
      })).data.data,
    enabled: !!datasetId,
  });
}

export interface PresetInfo {
  key: string;
  title: string;
  thresholds: Record<string, number | boolean>;
  reliability: string;
  sort: { field: string; direction: string }[];
}

export function usePresets() {
  return useQuery({
    queryKey: ['presets'],
    staleTime: 5 * 60 * 1000,
    queryFn: async () => (await api.get<{ data: Record<string, PresetInfo> }>('/presets')).data.data,
  });
}

export function useInvalidate() {
  const qc = useQueryClient();
  return {
    datasets: () => qc.invalidateQueries({ queryKey: ['datasets'] }),
    dataset: (id: string) => qc.invalidateQueries({ queryKey: ['dataset', id] }),
    niches: () => qc.invalidateQueries({ queryKey: ['niches'] }),
    niche: (id: string) => qc.invalidateQueries({ queryKey: ['niche', id] }),
    hypotheses: () => qc.invalidateQueries({ queryKey: ['hypotheses'] }),
    dashboard: () => qc.invalidateQueries({ queryKey: ['dashboard'] }),
    search: () => qc.invalidateQueries({ queryKey: ['search'] }),
  };
}

export function useApiMutation<TVars = void>(
  fn: (vars: TVars) => Promise<unknown>,
  invalidate: (qc: ReturnType<typeof useQueryClient>, vars: TVars) => void = () => {},
) {
  const qc = useQueryClient();
  return useMutation({
    mutationFn: fn,
    onSuccess: (_data, vars) => invalidate(qc, vars as TVars),
  });
}
