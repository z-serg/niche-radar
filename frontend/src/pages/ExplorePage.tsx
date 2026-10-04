import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { useQuery } from '@tanstack/react-query';
import { api, apiErrorMessage } from '../api/client';
import { useActiveDatasetId, useDatasets, usePresets, useSavedSearches, useSearch } from '../api/hooks';
import type { PresetInfo } from '../api/hooks';
import type { KeywordRow, SearchResponse } from '../api/types';
import { PageHeader } from '../components/Layout';
import { EXPLORE_HELP } from '../lib/help';
import { Badge, EmptyState, ErrorNote, Spinner } from '../components/ui';
import { NoActiveDatasetHint } from '../components/NoActiveDataset';
import { Sparkline } from '../components/charts';
import {
  FLAG_LABELS, NULL_REASON_LABELS, PRESET_LABELS, TASK_CATEGORY_LABELS, delta, fmtInt, fmtNum, fmtPct,
} from '../lib/format';

/** Состояние фильтров хранится в URL (ТЗ §5.2). */
export interface FilterState {
  text: string;
  mode: 'any' | 'all';
  match: 'token' | 'substring';
  excludeText: string;
  preset: string;
  exactMin: string;
  exactMax: string;
  growthMin: string;
  deltaMin: string;
  consistencyMin: string;
  retentionMin: string;
  baseMin: string;
  baseMax: string;
  wordMin: string;
  wordMax: string;
  taskMin: string;
  obsMin: string;
  isNew: boolean;
  lowBase: boolean;
  incomplete: boolean;
  sortField: string;
  sortDir: 'asc' | 'desc';
}

const DEFAULT_FILTERS: FilterState = {
  text: '', mode: 'any', match: 'token', excludeText: '', preset: '',
  exactMin: '', exactMax: '', growthMin: '', deltaMin: '', consistencyMin: '',
  retentionMin: '', baseMin: '', baseMax: '', wordMin: '', wordMax: '', taskMin: '',
  obsMin: '',
  isNew: false, lowBase: false, incomplete: false,
  sortField: 'priority', sortDir: 'desc',
};

function fromParams(params: URLSearchParams): FilterState {
  const get = (k: keyof FilterState) => params.get(k) ?? '';
  return {
    ...DEFAULT_FILTERS,
    text: get('text'), mode: (params.get('mode') as 'any' | 'all') || 'any',
    match: (params.get('match') as 'token' | 'substring') || 'token',
    excludeText: params.get('exclude') ?? '', preset: get('preset'),
    exactMin: get('exactMin'), exactMax: get('exactMax'), growthMin: get('growthMin'),
    deltaMin: get('deltaMin'), consistencyMin: params.get('consMin') ?? '', retentionMin: params.get('retMin') ?? '',
    baseMin: get('baseMin'), baseMax: get('baseMax'), wordMin: get('wordMin'), wordMax: get('wordMax'),
    taskMin: get('taskMin'), obsMin: get('obsMin'),
    isNew: params.get('isNew') === '1', lowBase: params.get('lowBase') === '1',
    incomplete: params.get('incomplete') === '1',
    ...(PRESET_DEFAULT_SORT[params.get('preset') ?? ''] ?? { sortField: 'priority', sortDir: 'desc' as const }),
  };
}

/** Дефолтная сортировка пресета, когда в URL нет явного sort. */
const PRESET_DEFAULT_SORT: Record<string, { sortField: string; sortDir: 'asc' | 'desc' }> = {
  growth_leaders: { sortField: 'smoothed_delta', sortDir: 'desc' },
  fall_leaders: { sortField: 'smoothed_delta', sortDir: 'asc' },
};

function filtersToApi(f: FilterState): Record<string, unknown> {
  const filters: Record<string, unknown> = {};
  const terms = f.text.split(/[\s,;]+/).map((t) => t.trim()).filter(Boolean);
  if (terms.length) {
    filters.include = { terms, mode: f.mode, match: f.match };
  }
  const ex = f.excludeText.split(/[\s,;]+/).map((t) => t.trim()).filter(Boolean);
  if (ex.length) {
    filters.exclude = { terms: ex, match: f.match };
  }
  const range = (v: string, field: string, minMax: 'min' | 'max') => {
    if (v === '') return;
    (filters[field] as Record<string, number> | undefined) ??= {};
    (filters[field] as Record<string, number>)[minMax] = Number(v);
  };
  range(f.exactMin, 'exact_current', 'min');
  range(f.exactMax, 'exact_current', 'max');
  range(f.growthMin, 'smoothed_growth', 'min');
  range(f.deltaMin, 'smoothed_delta', 'min');
  range(f.consistencyMin, 'consistency', 'min');
  range(f.retentionMin, 'peak_retention', 'min');
  range(f.baseMin, 'base_avg', 'min');
  range(f.baseMax, 'base_avg', 'max');
  range(f.wordMin, 'word_count', 'min');
  range(f.wordMax, 'word_count', 'max');
  range(f.taskMin, 'task_score', 'min');
  range(f.obsMin, 'n_observations', 'min');
  if (f.preset) filters.preset = f.preset;
  if (f.isNew) filters.is_new = true;
  if (f.lowBase) filters.low_base = true;
  if (f.incomplete) filters.history_incomplete = true;

  return filters;
}

/** Пороговые поля, которые задаются пресетом: их ручная правка означает,
 * что фильтры больше не соответствуют пресету — селект сбрасывается. */
const PRESET_BOUND_FIELDS: (keyof FilterState)[] = [
  'exactMin', 'exactMax', 'baseMin', 'baseMax', 'deltaMin', 'growthMin',
  'consistencyMin', 'retentionMin', 'obsMin',
];

const EMPTY_BOUND: Partial<FilterState> = {
  exactMin: '', exactMax: '', baseMin: '', baseMax: '', deltaMin: '',
  growthMin: '', consistencyMin: '', retentionMin: '', obsMin: '',
};

/** Заполнение пороговых полей фильтра значениями пресета (из GET /presets). */
function presetToBoundFilters(preset: PresetInfo): Partial<FilterState> {
  const t = preset.thresholds ?? {};
  const patch: Partial<FilterState> = { ...EMPTY_BOUND };
  const num = (v: number | boolean | undefined) =>
    v === undefined || v === null || typeof v === 'boolean' ? '' : String(v);

  if (t.exact_current_min !== undefined) patch.exactMin = num(t.exact_current_min);
  if (t.base_min !== undefined) patch.baseMin = num(t.base_min);
  if (t.base_max_exclusive !== undefined) patch.baseMax = num(t.base_max_exclusive);
  if (t.smoothed_delta_min !== undefined) patch.deltaMin = num(t.smoothed_delta_min);
  if (t.smoothed_growth_min !== undefined) patch.growthMin = num(t.smoothed_growth_min);
  if (t.consistency_min !== undefined) patch.consistencyMin = num(t.consistency_min);
  if (t.peak_retention_min !== undefined) patch.retentionMin = num(t.peak_retention_min);
  if (t.base_zero) { patch.baseMin = '0'; patch.baseMax = '0'; }
  if (t.min_observations !== undefined) patch.obsMin = num(t.min_observations);

  return patch;
}

const PRESET_SORT: Record<string, { sortField: string; sortDir: 'asc' | 'desc' }> = {
  growth_leaders: { sortField: 'smoothed_delta', sortDir: 'desc' },
  fall_leaders: { sortField: 'smoothed_delta', sortDir: 'asc' },
};

/** Плавный скролл к верху страницы при переключении пагинации. */
function scrollToTop() {
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

const SORT_OPTIONS: { value: string; label: string }[] = [
  { value: 'priority', label: 'Приоритет' },
  { value: 'exact_current', label: 'Точная частотность' },
  { value: 'smoothed_delta', label: 'Прирост A' },
  { value: 'smoothed_growth', label: 'Рост G' },
  { value: 'growth_pct', label: 'Рост границ, %' },
  { value: 'consistency', label: 'Устойчивость C' },
  { value: 'peak_retention', label: 'Сохранение пика P' },
  { value: 'rank_current', label: 'Позиция' },
  { value: 'rank_delta', label: 'Изменение позиции' },
  { value: 'phrase', label: 'Фраза' },
];

export function ExplorePage() {
  const navigate = useNavigate();
  const [params, setParams] = useSearchParams();
  const filters = useMemo(() => fromParams(params), [params]);
  const datasetId = useActiveDatasetId();
  // Стек курсоров: [null] — первая страница; дальше — next_cursor.
  const [cursorStack, setCursorStack] = useState<(string | null)[]>([null]);
  const cursor = cursorStack[cursorStack.length - 1];
  const [selected, setSelected] = useState<Set<number>>(new Set());
  const [saveName, setSaveName] = useState('');
  const [note, setNote] = useState<string | null>(null);
  const [pageInput, setPageInput] = useState('1');

  const { data: datasets } = useDatasets();
  const { data: saved } = useSavedSearches();
  const { data: presets } = usePresets();

  const activeDataset = datasets?.find((d) => d.id === datasetId);

  const searchParams = useMemo(() => ({
    dataset_id: datasetId ?? '',
    filters: filtersToApi(filters),
    sort: [{ field: filters.sortField, direction: filters.sortDir }],
    limit: 50,
    cursor,
  }), [datasetId, filters, cursor]);

  const query = useSearch(datasetId ? searchParams : null);
  const pageData: SearchResponse | undefined = query.data ?? undefined;

  // Общее число совпадений — асинхронно и отдельно от выдачи (ТЗ §5.2):
  // тяжёлый count не блокирует первую страницу.
  const { data: countData, isFetching: countFetching } = useQuery({
    queryKey: ['search-count', datasetId, filtersToApi(filters)],
    enabled: !!datasetId && !query.isError,
    staleTime: 60_000,
    queryFn: async () =>
      (await api.post<{ total_count: number | null }>('/keywords/count', {
        dataset_id: datasetId,
        filters: filtersToApi(filters),
      })).data,
  });
  const totalCount = countData?.total_count ?? null;
  const limit = searchParams.limit;
  const totalPages = totalCount !== null ? Math.max(1, Math.ceil(totalCount / limit)) : null;
  const currentPage = cursorStack.length;

  // Прыжок на произвольную страницу: назад — откат стека курсоров (мгновенно),
  // вперёд — последовательная прогрузка курсоров (без глубокого OFFSET, ТЗ §9).
  const [jumping, setJumping] = useState(false);
  async function goToPage(target: number) {
    if (!target || target < 1 || jumping) return;
    if (target === currentPage) return;
    const MAX_JUMP = 50;
    if (target > currentPage + MAX_JUMP) {
      setNote(`За один переход — не более ${MAX_JUMP} страниц (курсорная пагинация без OFFSET): перейдите на стр. ${currentPage + MAX_JUMP} и продолжите.`);
      return;
    }
    if (target < currentPage) {
      setCursorStack((s) => s.slice(0, target));
      scrollToTop();
      return;
    }
    setJumping(true);
    setSelected(new Set());
    try {
      let stack = [...cursorStack];
      while (stack.length < target) {
        const res = await api.post<SearchResponse>('/keywords/search', {
          dataset_id: datasetId,
          filters: filtersToApi(filters),
          sort: [{ field: filters.sortField, direction: filters.sortDir }],
          limit,
          cursor: stack[stack.length - 1],
        });
        if (!res.data.next_cursor) break;
        stack.push(res.data.next_cursor);
      }
      setCursorStack(stack.slice(0, target));
      scrollToTop();
    } catch (e) {
      setNote(apiErrorMessage(e));
    } finally {
      setJumping(false);
    }
  }

  // Заход по ссылке с пресетом (?preset=…): если пороговые поля ещё не
  // заданы — заполняем их значениями пресета, чтобы панель отражала
  // применяемые пороги (однократно за загрузку страницы).
  const urlPrefillDone = useRef(false);
  useEffect(() => {
    if (urlPrefillDone.current || !presets) return;
    urlPrefillDone.current = true;
    if (!filters.preset) return;
    const boundEmpty = PRESET_BOUND_FIELDS.every((f) => !filters[f]);
    if (!boundEmpty) return;
    const info = presets[filters.preset];
    if (!info) return;
    const patch = presetToBoundFilters(info);
    if (PRESET_BOUND_FIELDS.some((f) => patch[f])) {
      // preset передаётся явно: заполнение полей пресетом — не «ручная
      // правка», селект не сбрасывается.
      apply({ ...patch, preset: filters.preset });
    }
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [presets]);

  const apply = useCallback((patch: Partial<FilterState>) => {
    // Ручная правка порогов при активном пресете: параметры перестают
    // соответствовать пресету — селект возвращается к «без пресета».
    const touchesBound = PRESET_BOUND_FIELDS.some((f) => f in patch);
    if (touchesBound && filters.preset && !('preset' in patch)) {
      patch = { ...patch, preset: '' };
    }
    const next = { ...filters, ...patch };
    const p = new URLSearchParams();
    Object.entries(next).forEach(([k, v]) => {
      if (v === '' || v === false || v === null) return;
      const map: Record<string, string> = {
        text: 'text', excludeText: 'exclude', preset: 'preset', exactMin: 'exactMin',
        exactMax: 'exactMax', growthMin: 'growthMin', deltaMin: 'deltaMin',
        consistencyMin: 'consMin', retentionMin: 'retMin', baseMin: 'baseMin',
        baseMax: 'baseMax', wordMin: 'wordMin', wordMax: 'wordMax', taskMin: 'taskMin',
        obsMin: 'obsMin',
      };
      if (typeof v === 'boolean') {
        if (v) p.set(k, '1');
      } else if (map[k]) {
        p.set(map[k], v);
      } else {
        p.set(k, v);
      }
    });
    setParams(p, { replace: true });
    setCursorStack([null]);
  }, [filters, setParams]);

  async function exportSelection(kind: 'selection' | 'all') {
    try {
      const body = {
        kind: 'search_csv',
        dataset_id: datasetId,
        filters: filtersToApi(filters),
        format: 'tables',
        ...(kind === 'selection' && selected.size
          ? { filters: { ...filtersToApi(filters), include_keyword_ids: [] } }
          : {}),
      };
      // Экспорт всей выборки соответствует фильтрам, а не странице (ТЗ §14.2);
      // выбранные строки экспортируются отдельной нишей/списком.
      const res = await api.post<{ export_job_id: string }>('/exports', body);
      setNote(`Экспорт поставлен в очередь: ${res.data.export_job_id}. Статус — раздел «Данные» → Экспорты.`);
    } catch (e) {
      setNote(apiErrorMessage(e));
    }
  }

  async function saveSearch() {
    if (!saveName.trim()) return;
    try {
      await api.post('/saved-searches', {
        name: saveName.trim(),
        filters: filtersToApi(filters),
        report_mode: 'latest',
      });
      setSaveName('');
      setNote('Поиск сохранён.');
    } catch (e) {
      setNote(apiErrorMessage(e));
    }
  }

  if (!datasetId) {
    return (
      <>
        <PageHeader title="Исследование" />
        <div className="max-w-3xl p-6">
          <NoActiveDatasetHint />
        </div>
      </>
    );
  }

  const rows: KeywordRow[] = pageData?.data ?? [];
  const presetInfo = pageData?.applied?.preset;

  return (
    <>
      <PageHeader
        title="Исследование"
        help={EXPLORE_HELP}
        subtitle={
          activeDataset ? (
            <>
              отчёт: <b>{activeDataset.title}</b> · {fmtInt(activeDataset.row_count)} фраз ·{' '}
              {activeDataset.scan_count} замеров · охват: {activeDataset.coverage_type}
            </>
          ) : undefined
        }
        actions={
          <Link to="/data" className="btn-secondary">Сменить отчёт</Link>
        }
      />

      <div className="grid grid-cols-[320px_minmax(0,1fr)] gap-4 p-4">
        {/* --- Панель фильтров (все контролы доступны с клавиатуры) --- */}
        <aside className="card h-fit space-y-3 p-4 text-sm">
          <h2 className="text-xs font-semibold uppercase tracking-wider text-slate-500">Фильтры</h2>

          <div>
            <label className="label" htmlFor="f-text">Фраза / слова (включить)</label>
            <input
              id="f-text" className="input" value={filters.text}
              placeholder="конвертер, генератор…"
              onChange={(e) => apply({ text: e.target.value })}
            />
          </div>
          <div className="grid grid-cols-2 gap-2">
            <div>
              <label className="label" htmlFor="f-mode">Режим слов</label>
              <select id="f-mode" className="input" value={filters.mode} onChange={(e) => apply({ mode: e.target.value as 'any' | 'all' })}>
                <option value="any">любое из слов</option>
                <option value="all">все слова</option>
              </select>
            </div>
            <div>
              <label className="label" htmlFor="f-match">Совпадение</label>
              <select id="f-match" className="input" value={filters.match} onChange={(e) => apply({ match: e.target.value as 'token' | 'substring' })}>
                <option value="token">целый токен</option>
                <option value="substring">подстрока</option>
              </select>
            </div>
          </div>
          <div>
            <label className="label" htmlFor="f-exclude">Исключить слова</label>
            <input id="f-exclude" className="input" value={filters.excludeText} placeholder="игра, порно…" onChange={(e) => apply({ excludeText: e.target.value })} />
          </div>

          <div>
            <label className="label" htmlFor="f-preset">Пресет</label>
            <select
              id="f-preset"
              className="input"
              value={filters.preset}
              onChange={(e) => {
                const key = e.target.value;
                const info = key ? presets?.[key] : undefined;
                const sort = key
                  ? PRESET_SORT[key] ??
                    (info?.sort?.[0]
                      ? { sortField: info.sort[0].field, sortDir: info.sort[0].direction === 'asc' ? 'asc' as const : 'desc' as const }
                      : { sortField: 'priority', sortDir: 'desc' as const })
                  : undefined;
                apply({
                  preset: key,
                  ...(info ? presetToBoundFilters(info) : {}),
                  ...(sort ?? {}),
                });
              }}
            >
              <option value="">без пресета</option>
              {Object.entries(PRESET_LABELS).map(([k, v]) => <option key={k} value={k}>{v}</option>)}
            </select>
            {presetInfo && (
              <p className="mt-1 text-[11px] text-slate-500">
                {presetInfo.title} — {presetInfo.reliability}
              </p>
            )}
          </div>

          <div className="grid grid-cols-2 gap-2">
            {([
              ['exactMin', 'Точная от'], ['exactMax', 'Точная до'],
              ['deltaMin', 'Прирост A ≥'], ['growthMin', 'Рост G ≥ (доля)'],
              ['consistencyMin', 'Устойчивость C ≥'], ['retentionMin', 'Пик P ≥'],
              ['baseMin', 'База B от'], ['baseMax', 'База B до'],
              ['wordMin', 'Слов от'], ['wordMax', 'Слов до'],
              ['taskMin', 'Признак T ≥'], ['obsMin', 'Замеров ≥'],
            ] as [keyof FilterState, string][]).map(([key, label]) => (
              <div key={key}>
                <label className="label" htmlFor={`f-${key}`}>{label}</label>
                <input
                  id={`f-${key}`} className="input" inputMode="decimal"
                  value={filters[key] as string}
                  onChange={(e) => apply({ [key]: e.target.value } as Partial<FilterState>)}
                />
              </div>
            ))}
          </div>

          <fieldset className="space-y-1">
            <legend className="label">Признаки</legend>
            {([
              ['isNew', 'впервые в рейтинге'],
              ['lowBase', 'низкая база'],
              ['incomplete', 'неполная история'],
            ] as [keyof FilterState, string][]).map(([key, label]) => (
              <label key={key} className="flex items-center gap-2 text-sm">
                <input
                  type="checkbox" checked={filters[key] as boolean}
                  onChange={(e) => apply({ [key]: e.target.checked } as Partial<FilterState>)}
                  className="h-4 w-4 rounded border-slate-300"
                />
                {label}
              </label>
            ))}
          </fieldset>

          <button className="btn-secondary w-full" onClick={() => apply(DEFAULT_FILTERS)}>Сбросить фильтры</button>

          <div className="border-t border-slate-200 pt-3">
            <label className="label" htmlFor="f-save">Сохранить поиск как</label>
            <div className="flex gap-2">
              <input id="f-save" className="input" value={saveName} onChange={(e) => setSaveName(e.target.value)} placeholder="например: конвертация pdf" />
              <button className="btn-secondary" onClick={saveSearch}>Сохранить</button>
            </div>
            {saved && saved.length > 0 && (
              <ul className="mt-2 space-y-1">
                {saved.map((s) => (
                  <li key={s.id}>
                    <button
                      className="text-left text-xs text-brand-700 hover:underline"
                      onClick={() => { window.sessionStorage.setItem(`saved-search:${s.id}`, JSON.stringify(s.filters)); navigate(`/explore?applied=${s.id}`); window.location.reload(); }}
                    >
                      ★ {s.name}
                    </button>
                  </li>
                ))}
              </ul>
            )}
          </div>
        </aside>

        {/* --- Таблица результатов (серверная) --- */}
        <section className="min-w-0 space-y-3">
          {note && <div className="rounded border border-brand-200 bg-brand-50 px-3 py-2 text-xs text-brand-800">{note}</div>}
          {query.isFetching && <div className="flex items-center gap-2 text-xs text-slate-500"><Spinner /> загрузка…</div>}
          {query.isError && <ErrorNote message={apiErrorMessage(query.error)} />}

          {rows.length === 0 && !query.isFetching && !query.isError ? (
            <EmptyState
              title="Ничего не найдено"
              hint="Проверьте фильтры и пороги. Пресет «Устойчивый рост» строг: на маленькой выборке может не быть совпадений — это корректный пустой результат."
            />
          ) : (
            <div className="card overflow-x-auto">
              <table className="w-full border-collapse">
                <thead className="border-b border-slate-200 bg-slate-50">
                  <tr>
                    <th className="th w-8"><span className="sr-only">выбор</span></th>
                    <th className="th">Фраза</th>
                    <th className="th text-right" title="Точная частотность текущего замера: показы в точном написании">Точная</th>
                    <th className="th text-right" title="Широкая частотность: показы с учётом всех форм слова; не суммируется как рынок">Широкая</th>
                    <th className="th text-right" title="Позиция в рейтинге текущего отчёта">Поз.</th>
                    <th className="th text-right" title="Изменение позиции с прошлого замера; «нов.» — не было в прошлом рейтинге">Δ поз.</th>
                    <th className="th" title="Мини-график точной частотности: слева старейший замер, справа текущий">История</th>
                    <th className="th text-right" title="A = R − B: сглаженный прирост в показах (поздний уровень минус ранняя база, по двум замерам)">Прирост A</th>
                    <th className="th text-right" title="G = (R − B) / B: рост к ранней базе, 0,3 = +30 %">Рост G</th>
                    <th className="th text-right" title="C — устойчивость: доля переходов между замерами с ростом (равные — не рост)">C</th>
                    <th className="th text-right" title="P — сохранение спроса: доля текущей частотности от пика ряда">P</th>
                    <th className="th text-right" title="Приоритет исследования 0–100: 0,30·g + 0,25·a + 0,20·C + 0,15·v + 0,10·T; подробнее — по иконке «?»">Приоритет</th>
                    <th className="th" title="Категории задачи для онлайн-сервиса и особые состояния (впервые в рейтинге, низкая база и т.п.)">Признаки</th>
                  </tr>
                </thead>
                <tbody>
                  {rows.map((r) => (
                    <KeywordTableRow
                      key={r.keyword_id}
                      row={r}
                      datasetId={datasetId}
                      checked={selected.has(r.keyword_id)}
                      onToggle={() => {
                        const next = new Set(selected);
                        if (next.has(r.keyword_id)) next.delete(r.keyword_id);
                        else next.add(r.keyword_id);
                        setSelected(next);
                      }}
                    />
                  ))}
                </tbody>
              </table>
            </div>
          )}

          <div className="flex flex-wrap items-center justify-between gap-3 text-sm">
            <span className="text-xs text-slate-500">
              {totalCount !== null
                ? <>Совпадений: <b>{fmtInt(totalCount)}</b>{countFetching ? ' (пересчёт…)' : ''}</>
                : countFetching ? 'подсчёт совпадений…' : ''}
              {totalPages !== null && ` · страниц: ${fmtInt(totalPages)}`}
              {pageData ? ` · стр. ${currentPage}` : ''}
              {pageData ? `, строк на странице: ${rows.length}` : ''}
              {pageData?.truncated ? ' · есть ещё совпадения' : ''}
              {pageData?.hard_limit_reached ? ' · достигнут жёсткий лимит выдачи' : ''}
            </span>
            <div className="flex items-center gap-2">
              <select
                className="input w-auto"
                value={filters.sortField}
                onChange={(e) => apply({ sortField: e.target.value })}
                aria-label="Поле сортировки"
              >
                {SORT_OPTIONS.map((o) => <option key={o.value} value={o.value}>{o.label}</option>)}
              </select>
              <button
                className="btn-secondary"
                onClick={() => apply({ sortDir: filters.sortDir === 'desc' ? 'asc' : 'desc' })}
              >
                {filters.sortDir === 'desc' ? '↓ убыв.' : '↑ возр.'}
              </button>
              <button
                className="btn-secondary"
                disabled={currentPage <= 1 || jumping}
                onClick={() => { setCursorStack((s) => s.slice(0, -1)); scrollToTop(); }}
                title="На страницу назад"
              >
                ← Назад
              </button>
              <button
                className="btn-secondary"
                disabled={!pageData?.next_cursor || jumping}
                onClick={() => {
                  if (!pageData?.next_cursor) return;
                  setSelected(new Set());
                  setCursorStack((s) => [...s, pageData.next_cursor!]);
                  scrollToTop();
                }}
              >
                Вперёд →
              </button>
              <span className="flex items-center gap-1 text-xs text-slate-500">
                {jumping ? <><Spinner className="h-3 w-3" /> переход…</> : (
                  <>
                    стр.
                    <input
                      type="number"
                      min={1}
                      max={totalPages ?? undefined}
                      className="input w-20 py-0.5 text-xs"
                      value={pageInput}
                      onChange={(e) => setPageInput(e.target.value)}
                      onKeyDown={(e) => { if (e.key === 'Enter') goToPage(Number(pageInput)); }}
                      aria-label="Номер страницы"
                    />
                    {totalPages !== null && <span>из {fmtInt(totalPages)}</span>}
                    <button
                      className="btn-secondary px-2 py-0.5 text-xs"
                      onClick={() => goToPage(Number(pageInput))}
                      disabled={jumping}
                    >
                      Перейти
                    </button>
                  </>
                )}
              </span>
              {currentPage > 1 && (
                <button className="btn-secondary" onClick={() => { setSelected(new Set()); setCursorStack([null]); scrollToTop(); }}>
                  ⇤ В начало
                </button>
              )}
              <button className="btn-secondary" onClick={() => exportSelection('all')}>Экспорт CSV (вся выборка)</button>
            </div>
          </div>

          {selected.size > 0 && (
            <div className="card flex items-center justify-between gap-3 p-3 text-sm">
              <span>выбрано фраз: <b>{selected.size}</b></span>
              <button
                className="btn-primary"
                onClick={async () => {
                  window.sessionStorage.setItem(
                    'niche-candidates',
                    JSON.stringify(rows.filter((r) => selected.has(r.keyword_id)).map((r) => ({ id: r.keyword_id, phrase: r.phrase }))),
                  );
                  navigate('/niches?create=from-selection');
                }}
              >
                Добавить выбранные в нишу
              </button>
            </div>
          )}
        </section>
      </div>
    </>
  );
}

function KeywordTableRow({
  row, datasetId, checked, onToggle,
}: {
  row: KeywordRow;
  datasetId: string;
  checked: boolean;
  onToggle: () => void;
}) {
  const d = delta(row.metrics.smoothed_delta);
  const nullReasons = Object.entries(row.metrics.null_reasons ?? {});

  return (
    <tr className="border-b border-slate-100 hover:bg-slate-50">
      <td className="td">
        <input type="checkbox" checked={checked} onChange={onToggle} className="h-4 w-4 rounded border-slate-300" aria-label={`выбрать ${row.phrase}`} />
      </td>
      <td className="td max-w-[340px] truncate font-medium">
        <Link target="_blank" rel="noopener" to={`/keywords/${row.keyword_id}?dataset_id=${datasetId}`} className="text-brand-700 hover:underline">
          {row.phrase}
        </Link>
      </td>
      <td className="td text-right tabular-nums">{fmtInt(row.exact_current)}</td>
      <td className="td text-right tabular-nums text-slate-500">{fmtInt(row.broad_current)}</td>
      <td className="td text-right tabular-nums">{fmtInt(row.rank_current)}</td>
      <td className="td text-right tabular-nums">
        {row.rank_previous === 0 ? (
          <Badge tone="violet" title="rank_previous = 0">нов.</Badge>
        ) : (
          <span className={clsx('tabular-nums', (row.rank_delta ?? 0) > 0 ? 'text-emerald-700' : 'text-slate-600')}>
            {row.rank_delta === null ? '—' : row.rank_delta > 0 ? `▲${row.rank_delta}` : row.rank_delta < 0 ? `▼${Math.abs(row.rank_delta)}` : '=0'}
          </span>
        )}
      </td>
      <td className="td"><Sparkline history={row.history} /></td>
      <td className={clsx('td text-right tabular-nums', d.cls)} title={d.text}>{d.text}</td>
      <td className="td text-right tabular-nums">{fmtPct(row.metrics.smoothed_growth)}</td>
      <td className="td text-right tabular-nums">{row.metrics.consistency === null ? '—' : fmtNum(row.metrics.consistency, 2)}</td>
      <td className="td text-right tabular-nums">{row.metrics.peak_retention === null ? '—' : fmtNum(row.metrics.peak_retention, 2)}</td>
      <td className="td text-right">
        {row.metrics.priority === null ? (
          <span className="text-xs text-slate-400" title={nullReasons.map(([k, v]) => `${k}: ${NULL_REASON_LABELS[v] ?? v}`).join(', ')}>
            н/д
          </span>
        ) : (
          <span className="inline-flex h-6 min-w-8 items-center justify-center rounded bg-brand-600 px-1.5 text-xs font-bold text-white">
            {row.metrics.priority}
          </span>
        )}
      </td>
      <td className="td">
        <div className="flex max-w-[260px] flex-wrap gap-1">
          {row.metrics.task_categories
            .filter((c) => c !== 'weak')
            .map((c) => <Badge key={c} tone="blue">{TASK_CATEGORY_LABELS[c] ?? c}</Badge>)}
          {row.flags.map((f) => (
            <Badge key={f} tone={f === 'is_new' ? 'violet' : 'amber'}>{FLAG_LABELS[f] ?? f}</Badge>
          ))}
        </div>
      </td>
    </tr>
  );
}
