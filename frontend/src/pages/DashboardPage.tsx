import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { useActiveDatasetId, useDashboard, useDatasets } from '../api/hooks';
import { PageHeader } from '../components/Layout';
import { DASHBOARD_HELP } from '../lib/help';
import { Spinner, StatCard } from '../components/ui';
import { NoActiveDatasetHint } from '../components/NoActiveDataset';
import { fmtInt, fmtNum, fmtPct } from '../lib/format';

export function DashboardPage() {
  const datasets = useDatasets();
  const [selected, setSelected] = useStateActive();
  const { data, isLoading } = useDashboard(selected);

  if (isLoading || datasets.isLoading) {
    return <><PageHeader title="Дашборд" /><div className="p-6 flex items-center gap-2 text-sm text-slate-500"><Spinner /> загрузка…</div></>;
  }
  if (!data) {
    return (
      <>
        <PageHeader title="Дашборд" />
        <div className="max-w-3xl p-6">
          <NoActiveDatasetHint />
        </div>
      </>
    );
  }

  const presets = data.preset_counts ?? {};
  const ds = data.dataset;

  return (
    <>
      <PageHeader
        title="Обзор отчёта"
        help={DASHBOARD_HELP}
        subtitle={<>{ds.title} · {fmtInt(ds.row_count)} фраз · {ds.scan_count} замеров · даты замеров неизвестны — ось порядковая</>}
        actions={
          <select className="input w-auto" value={selected ?? ''} onChange={(e) => setSelected(e.target.value)}>
            {datasets.data?.filter((d) => d.status === 'ready').map((d) => (
              <option key={d.id} value={d.id}>{d.title}{d.is_active ? ' (активный)' : ''}</option>
            ))}
          </select>
        }
      />
      <div className="space-y-4 p-4">
        {ds.quality_flags.length > 0 && (
          <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
            <b>Предупреждения качества:</b> {ds.quality_flags.join(', ')}
          </div>
        )}

        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <StatCard label="Фраз в отчёте" value={fmtInt(ds.row_count)} hint={`охват: ${ds.coverage_type}`} />
          <StatCard
            label="История замеров"
            value={ds.has_history ? `${ds.scan_count} замеров` : 'нет истории'}
            hint={ds.has_history ? 'порядковая ось; календарные темпы не считаются' : 'только текущий замер'}
          />
          <Link to="/candidates" className="contents">
            <StatCard
              label="Кандидаты «Устойчивый рост»"
              value={fmtInt(presets.sustained_growth ?? 0)}
              hint="открывает раздел «Кандидаты»"
            />
          </Link>
          <StatCard
            label="Новые участники рейтинга"
            value={fmtInt(data.new_entrants_count ?? 0)}
            hint="«впервые в рейтинге» ≠ «впервые возникший спрос»"
          />
        </div>

        <div className="grid grid-cols-2 gap-3 md:grid-cols-4">
          <StatCard label="«Ранние сигналы»" value={fmtInt(presets.early_signals ?? 0)} hint="0 < B < 20; надёжность ниже" />
          <StatCard label="«Рост от нуля»" value={fmtInt(presets.zero_baseline ?? 0)} hint="B = 0; G не определён" />
          <StatCard label="Версия расчёта" value={data.metric_run?.algorithm_version ?? '—'} hint={`правила: ${data.metric_run?.rules_version ?? '—'}`} />
          <StatCard label="Замеры (ordinal)" value={`${data.scans[0]?.ordinal}…${data.scans[data.scans.length - 1]?.ordinal}`} hint="1 — самый старый" />
        </div>

        <div className="grid grid-cols-2 gap-4">
          <LeadersCard title="Лидеры роста" rows={data.growth_leaders ?? []} datasetId={selected} rising preset="growth_leaders" />
          <LeadersCard title="Лидеры падения" rows={data.fall_leaders ?? []} datasetId={selected} preset="fall_leaders" />
        </div>

        <section className="card p-4">
          <h2 className="mb-2 text-sm font-semibold">Новые участники рейтинга (примеры)</h2>
          {data.new_entrants_sample?.length ? (
            <ul className="flex flex-wrap gap-2">
              {data.new_entrants_sample.map((r) => (
                <li key={r.keyword_id}>
                  <Link
                    to={`/keywords/${r.keyword_id}?dataset_id=${selected}`}
                    className="inline-flex items-center gap-2 rounded border border-violet-200 bg-violet-50 px-2 py-1 text-xs hover:bg-violet-100"
                  >
                    {r.phrase}
                    <span className="text-slate-500">{fmtInt(r.exact_current)}</span>
                  </Link>
                </li>
              ))}
            </ul>
          ) : (
            <p className="text-xs text-slate-400">нет фраз с rank_previous = 0</p>
          )}
        </section>
      </div>
    </>
  );
}

function LeadersCard({
  title, rows, datasetId, rising, preset,
}: {
  title: string;
  rows: import('../api/types').LeaderRow[];
  datasetId: string | null;
  rising?: boolean;
  preset: string;
}) {
  return (
    <section className="card p-4">
      <h2 className="mb-2 text-sm font-semibold">
        <Link to={`/explore?preset=${preset}`} className="hover:text-brand-700 hover:underline" title="Открыть выборку в «Исследовании»">
          {title} (сглаженный прирост A)
        </Link>
      </h2>
      {rows.length === 0 ? (
        <p className="text-xs text-slate-400">нет данных</p>
      ) : (
        <table className="w-full text-sm">
          <tbody>
            {rows.map((r) => (
              <tr key={r.keyword_id} className="border-b border-slate-100 last:border-0">
                <td className="py-1 pr-2">
                  <Link target="_blank" rel="noopener" to={`/keywords/${r.keyword_id}?dataset_id=${datasetId}`} className="text-brand-700 hover:underline">
                    {r.phrase}
                  </Link>
                </td>
                <td className={clsx('py-1 text-right tabular-nums', rising ? 'text-emerald-700' : 'text-rose-700')}>
                  {r.smoothed_delta == null ? '—' : fmtInt(r.smoothed_delta)}
                </td>
                <td className="py-1 pl-3 text-right text-xs tabular-nums text-slate-500">
                  {fmtPct(r.smoothed_growth)} · P:{r.priority ?? '—'}
                </td>
              </tr>
            ))}
          </tbody>
        </table>
      )}
    </section>
  );
}

function useStateActive(): [string | null, (v: string) => void] {
  const active = useActiveDatasetId();
  const datasets = useDatasets();
  const [manual, setManual] = useState<string | null>(null);
  const value = manual ?? active;
  return [value, setManual];
}
