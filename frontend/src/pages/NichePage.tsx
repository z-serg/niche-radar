import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { api, apiErrorMessage } from '../api/client';
import { useActiveDatasetId, useInvalidate, useNiche } from '../api/hooks';
import { PageHeader } from '../components/Layout';
import { NICHE_HELP } from '../lib/help';
import { Badge, EmptyState, Spinner } from '../components/ui';
import { fmtInt, fmtNum, fmtPct } from '../lib/format';

export function NichePage() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const activeDatasetId = useActiveDatasetId();
  const evaluateWith = params.get('evaluate') ?? activeDatasetId;
  const { data: niche, isLoading, error } = useNiche(id ?? null);
  const invalidate = useInvalidate();
  const [busy, setBusy] = useState(false);
  const [evalError, setEvalError] = useState<string | null>(null);
  const [deleting, setDeleting] = useState(false);

  const latest = niche?.versions?.[0] ?? null;

  const members = useQuery({
    queryKey: ['niche-members', id],
    queryFn: async () =>
      (await api.get(`/niches/${id}/members`, { params: { limit: 200 } })).data as {
        data: { keyword_id: number; phrase_original: string; has_observation: boolean; exact_current: number | null; smoothed_delta: number | null; priority: number | null }[];
        version: { id: string; version: number };
      },
    enabled: !!id,
  });

  async function evaluate() {
    if (!id || !evaluateWith) return;
    setBusy(true);
    setEvalError(null);
    try {
      await api.post(`/niches/${id}/evaluate`, { dataset_id: evaluateWith });
      invalidate.niche(id);
      members.refetch();
    } catch (e) {
      setEvalError(apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  async function exportCsv() {
    if (!latest) return;
    await api.post('/exports', { kind: 'niche_csv', niche_version_id: latest.id, format: 'tables' });
  }

  async function deleteNiche() {
    if (!id) return;
    if (!window.confirm(`Удалить нишу «${niche?.name}» со всеми версиями состава? Действие необратимо.`)) return;
    setDeleting(true);
    try {
      await api.delete(`/niches/${id}`);
      invalidate.niches();
      window.location.href = '/niches';
    } catch (e) {
      setEvalError(apiErrorMessage(e));
    } finally {
      setDeleting(false);
    }
  }

  if (isLoading) {
    return <><PageHeader title="Ниша" help={NICHE_HELP} /><div className="p-6 flex items-center gap-2 text-sm text-slate-500"><Spinner /> загрузка…</div></>;
  }
  if (error || !niche) {
    return <><PageHeader title="Ниша" help={NICHE_HELP} /><div className="p-6"><EmptyState title="Ниша не найдена" hint={apiErrorMessage(error)} /></div></>;
  }

  const agg = latest?.aggregates;

  return (
    <>
      <PageHeader
        title={niche.name}
        help={NICHE_HELP}
        subtitle={niche.description ?? undefined}
        actions={
          <>
            <button className="btn-secondary" onClick={exportCsv} disabled={!latest}>Экспорт CSV</button>
            <button className="btn-danger" onClick={deleteNiche} disabled={deleting}>
              {deleting ? <><Spinner /> удаление…</> : 'Удалить нишу'}
            </button>
            <button className="btn-primary" onClick={evaluate} disabled={busy || !evaluateWith}>
              {busy ? <><Spinner /> оценка…</> : latest ? 'Пересчитать (новая версия)' : 'Оценить состав'}
            </button>
          </>
        }
      />
      <div className="grid grid-cols-[minmax(0,1fr)_380px] gap-4 p-4">
        <div className="space-y-4">
          {evalError && <div className="rounded border border-rose-200 bg-rose-50 p-2 text-sm text-rose-800">{evalError}</div>}

          {/* Состав последней версии */}
          <section className="card">
            <div className="flex items-center justify-between border-b border-slate-200 px-4 py-2">
              <h2 className="text-sm font-semibold">
                Состав {latest ? `(версия ${latest.version})` : '(не оценён)'}
              </h2>
              {latest && (
                <span className="text-xs text-slate-500">
                  {fmtInt(latest.member_count)} фраз · полным рядом {fmtInt(latest.complete_member_count)} · покрытие {fmtNum(latest.coverage_pct, 0)}%
                </span>
              )}
            </div>
            {members.isLoading ? (
              <div className="p-4"><Spinner /></div>
            ) : members.data?.data.length === 0 ? (
              <p className="p-4 text-sm text-slate-500">Состав пуст: нажмите «Оценить состав».</p>
            ) : (
              <div className="max-h-[480px] overflow-y-auto">
                <table className="w-full">
                  <thead className="sticky top-0 bg-slate-50">
                    <tr>
                      <th className="th">Фраза</th>
                      <th className="th text-right">Точная</th>
                      <th className="th text-right">Прирост A</th>
                      <th className="th text-right">Приоритет</th>
                    </tr>
                  </thead>
                  <tbody>
                    {members.data?.data.map((m) => (
                      <tr key={m.keyword_id} className="border-b border-slate-100">
                        <td className="td">
                          <Link target="_blank" rel="noopener" to={`/keywords/${m.keyword_id}?dataset_id=${activeDatasetId}`} className="text-brand-700 hover:underline">
                            {m.phrase_original}
                          </Link>
                          {!m.has_observation && <Badge tone="amber">нет наблюдения в отчёте</Badge>}
                        </td>
                        <td className="td text-right tabular-nums">{fmtInt(m.exact_current)}</td>
                        <td className="td text-right tabular-nums">{fmtInt(m.smoothed_delta)}</td>
                        <td className="td text-right tabular-nums">{m.priority ?? '—'}</td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            )}
            {agg?.excluded_incomplete ? (
              <p className="border-t border-slate-100 px-4 py-2 text-xs text-amber-700">
                Из динамики исключено фраз из-за неполной истории: {agg.excluded_incomplete} (не заменяются нулями).
              </p>
            ) : null}
          </section>
        </div>

        {/* Агрегаты версии */}
        <div className="space-y-4">
          <section className="card space-y-2 p-4 text-sm">
            <h2 className="text-sm font-semibold">Оценка версии {latest?.version ?? '—'}</h2>
            {!agg ? (
              <p className="text-xs text-slate-500">Оцените состав, чтобы получить агрегаты.</p>
            ) : (
              <>
                <p className="text-xs text-slate-500">{agg.exact_sum_label}</p>
                <dl className="space-y-1.5">
                  <Row label="Сумма точных (текущий замер)" value={fmtInt(agg.dynamics?.[agg.dynamics.length - 1]?.exact_sum ?? null)} />
                  <Row label="Прирост суммы" value={`${fmtInt(agg.growth_abs ?? null)} (${fmtPct(agg.growth_pct ?? null)})`} />
                  <Row label="Доля растущих участников" value={fmtPct(agg.growing_share ?? null, false)} />
                  <Row label="Доля лидирующей фразы" value={fmtPct(agg.leader_share ?? null, false)} />
                  <Row
                    label="Медиана G (база > 0)"
                    value={`${fmtPct(agg.median_smoothed_growth?.value ?? null, false)} · участников: ${agg.median_smoothed_growth?.positive_base_members ?? 0}`}
                  />
                  {agg.leader && <Row label="Лидер" value={agg.leader.phrase ?? `#${agg.leader.keyword_id}`} />}
                </dl>
                <p className="border-t border-slate-100 pt-2 text-[11px] text-slate-500">
                  Прежние версии не изменяются: пересчёт создаёт новую версию состава.
                </p>
              </>
            )}
          </section>

          {agg?.contribution_leaders && agg.contribution_leaders.length > 0 && (
            <section className="card p-4">
              <h2 className="mb-2 text-sm font-semibold">Лидеры вклада в изменение</h2>
              <ul className="space-y-1 text-sm">
                {agg.contribution_leaders.map((c) => (
                  <li key={c.keyword_id} className="flex items-center justify-between gap-2">
                    <Link target="_blank" rel="noopener" to={`/keywords/${c.keyword_id}?dataset_id=${activeDatasetId}`} className="truncate text-brand-700 hover:underline">
                      {c.phrase ?? `#${c.keyword_id}`}
                    </Link>
                    <span className="tabular-nums text-xs">{fmtInt(c.smoothed_delta)}</span>
                  </li>
                ))}
              </ul>
            </section>
          )}

          {/* История версий */}
          <section className="card p-4">
            <h2 className="mb-2 text-sm font-semibold">История версий</h2>
            <ul className="space-y-1 text-xs">
              {niche.versions?.map((v) => (
                <li key={v.id} className="flex items-center justify-between gap-2 rounded border border-slate-100 px-2 py-1">
                  <span>версия {v.version} · {fmtInt(v.member_count)} фраз</span>
                  <span className="text-slate-400">{new Date(v.created_at).toLocaleDateString('ru-RU')}</span>
                </li>
              )) ?? null}
            </ul>
          </section>
        </div>
      </div>
    </>
  );
}

function Row({ label, value }: { label: string; value: string }) {
  return (
    <div className="flex items-center justify-between gap-2 border-b border-slate-50 pb-1">
      <dt className="text-xs text-slate-500">{label}</dt>
      <dd className="text-right font-medium tabular-nums">{value}</dd>
    </div>
  );
}
