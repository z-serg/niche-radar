import { useState } from 'react';
import { Link } from 'react-router-dom';
import clsx from 'clsx';
import { useQuery } from '@tanstack/react-query';
import { api } from '../api/client';
import { useActiveDatasetId, useCandidateGroups } from '../api/hooks';
import type { CandidateGroup, KeywordRow } from '../api/types';
import { PageHeader } from '../components/Layout';
import { CANDIDATES_HELP } from '../lib/help';
import { Badge, EmptyState, Modal, Spinner } from '../components/ui';
import { NoActiveDatasetHint } from '../components/NoActiveDataset';
import { fmtInt, fmtNum, fmtPct } from '../lib/format';

export function CandidatesPage() {
  const datasetId = useActiveDatasetId();
  const [tab, setTab] = useState<'phrases' | 'groups'>('phrases');
  const [preset, setPreset] = useState('sustained_growth');
  const [expanded, setExpanded] = useState<CandidateGroup | null>(null);

  const groups = useCandidateGroups(datasetId);

  return (
    <>
      <PageHeader
        title="Кандидаты"
        subtitle="растущие фразы по пресетам и предложения групп по словам"
        help={CANDIDATES_HELP}
      />
      <div className="space-y-4 p-4">
        <div className="flex items-center gap-2">
          <div className="flex rounded-md border border-slate-300 bg-white p-0.5">
            <button
              className={clsx('rounded px-3 py-1 text-sm font-medium', tab === 'phrases' ? 'bg-brand-600 text-white' : 'text-slate-600')}
              onClick={() => setTab('phrases')}
            >
              Растущие фразы
            </button>
            <button
              className={clsx('rounded px-3 py-1 text-sm font-medium', tab === 'groups' ? 'bg-brand-600 text-white' : 'text-slate-600')}
              onClick={() => setTab('groups')}
            >
              Предложения групп
            </button>
          </div>
        </div>

        {tab === 'phrases' && <PresetPhrases datasetId={datasetId} preset={preset} setPreset={setPreset} />}
        {tab === 'groups' && (
          groups.isLoading ? (
            <div className="flex items-center gap-2 p-4 text-sm text-slate-500"><Spinner /> расчёт групп…</div>
          ) : !datasetId ? (
            <div className="max-w-3xl"><NoActiveDatasetHint /></div>
          ) : groups.data?.length === 0 ? (
            <EmptyState
              title="Групп нет"
              hint="Группы строятся по фразам, прошедшим пресет «Устойчивый рост». Если таких фраз нет — предложений не будет."
            />
          ) : (
            <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
              {groups.data?.map((g) => (
                <article key={g.id} className="card space-y-2 p-4">
                  <div className="flex items-center justify-between gap-2">
                    <h3 className="truncate font-semibold" title={g.anchor}>{g.anchor}</h3>
                    <Badge tone={g.method === 'token' ? 'slate' : 'violet'}>{g.method === 'token' ? 'слово' : 'пара слов'}</Badge>
                  </div>
                  <p className="text-xs text-slate-500">{g.description}</p>
                  <dl className="grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                    <div><dt className="text-slate-400">фраз в группе</dt><dd className="font-medium">{fmtInt(g.member_count)}</dd></div>
                    <div><dt className="text-slate-400">сумма точных</dt><dd className="font-medium">{fmtInt(g.exact_sum_current)}</dd></div>
                    <div><dt className="text-slate-400">доля растущих</dt><dd className="font-medium">{fmtPct(g.growing_share, false)}</dd></div>
                    <div><dt className="text-slate-400">доля лидера</dt><dd className="font-medium">{fmtPct(g.leader_share, false)}</dd></div>
                  </dl>
                  <div className="flex gap-2 pt-1">
                    <button className="btn-secondary flex-1 text-xs" onClick={() => setExpanded(g)}>Примеры и «Расширить»</button>
                    <SaveGroupAsNiche group={g} datasetId={datasetId} />
                  </div>
                </article>
              ))}
            </div>
          )
        )}
      </div>

      {expanded && <GroupMembersModal group={expanded} datasetId={datasetId} onClose={() => setExpanded(null)} />}
    </>
  );
}

function PresetPhrases({
  datasetId, preset, setPreset,
}: {
  datasetId: string | null;
  preset: string;
  setPreset: (p: string) => void;
}) {
  const { data, isLoading } = useQuery({
    queryKey: ['preset-search', datasetId, preset],
    enabled: !!datasetId,
    queryFn: async () =>
      // Сортировку задаёт пресет на сервере (priority / A по убыванию или возрастанию).
      (await api.post('/keywords/search', {
        dataset_id: datasetId,
        filters: { preset },
        limit: 50,
      })).data as { data: KeywordRow[]; truncated: boolean },
  });

  const presetNotes: Record<string, string> = {
    sustained_growth: 'основной рейтинг устойчивого роста',
    early_signals: 'низкая база (0 < B < 20): сигнал пониженной надёжности, не смешивается с основным рейтингом',
    zero_baseline: 'рост от нуля: G и основной приоритет не определены, сортировка по абсолютному приросту',
    growth_leaders: 'максимальный сглаженный прирост A без порогов — как карточка «Лидеры роста» на дашборде',
    fall_leaders: 'минимальный сглаженный прирост A без порогов — как карточка «Лидеры падения» на дашборде',
  };

  return (
    <div className="space-y-3">
      <div className="flex items-center gap-2">
        <select className="input w-64" value={preset} onChange={(e) => setPreset(e.target.value)} aria-label="Пресет">
          <option value="sustained_growth">Устойчивый рост</option>
          <option value="early_signals">Ранние сигналы</option>
          <option value="zero_baseline">Рост от нуля</option>
          <option value="growth_leaders">Лидеры роста</option>
          <option value="fall_leaders">Лидеры падения</option>
        </select>
        <span className="text-xs text-slate-500">{presetNotes[preset]}</span>
      </div>

      {isLoading && <div className="flex items-center gap-2 p-4 text-sm text-slate-500"><Spinner /> загрузка…</div>}
      {data?.data.length === 0 && (
        <EmptyState
          title="Нет фраз, проходящих пресет"
          hint="Пороги пресета неизменны для этой версии правил; пустой результат — корректный ответ, а не ошибка."
        />
      )}
      {data && data.data.length > 0 && (
        <div className="card overflow-x-auto">
          <table className="w-full">
            <thead className="border-b border-slate-200 bg-slate-50">
              <tr>
                <th className="th">Фраза</th>
                <th className="th text-right">Точная</th>
                <th className="th text-right">Прирост A</th>
                <th className="th text-right">Рост G</th>
                <th className="th text-right">C</th>
                <th className="th text-right">P</th>
                <th className="th text-right">Приоритет</th>
              </tr>
            </thead>
            <tbody>
              {data.data.map((r) => (
                <tr key={r.keyword_id} className="border-b border-slate-100 hover:bg-slate-50">
                  <td className="td">
                    <Link target="_blank" rel="noopener" to={`/keywords/${r.keyword_id}?dataset_id=${datasetId}`} className="font-medium text-brand-700 hover:underline">
                      {r.phrase}
                    </Link>
                  </td>
                  <td className="td text-right tabular-nums">{fmtInt(r.exact_current)}</td>
                  <td className={clsx('td text-right tabular-nums', (r.metrics.smoothed_delta ?? 0) > 0 ? 'text-emerald-700' : 'text-rose-700')}>
                    {fmtInt(r.metrics.smoothed_delta)}
                  </td>
                  <td className="td text-right tabular-nums">{fmtPct(r.metrics.smoothed_growth)}</td>
                  <td className="td text-right tabular-nums">{fmtNum(r.metrics.consistency, 2)}</td>
                  <td className="td text-right tabular-nums">{fmtNum(r.metrics.peak_retention, 2)}</td>
                  <td className="td text-right tabular-nums font-semibold">{r.metrics.priority ?? 'н/д'}</td>
                </tr>
              ))}
            </tbody>
          </table>
          {data.truncated && <p className="px-3 py-2 text-xs text-slate-500">показаны первые 50; продолжение — в разделе «Исследование» с этим пресетом</p>}
        </div>
      )}
    </div>
  );
}

function SaveGroupAsNiche({ group, datasetId }: { group: CandidateGroup; datasetId: string | null }) {
  const [state, setState] = useState<'idle' | 'busy' | 'done' | 'error'>('idle');

  async function save() {
    setState('busy');
    try {
      // Сохраняем как нишу-правило по якорю; состав проверяется при оценке.
      const filters = group.method === 'token'
        ? { include: { terms: [group.anchor], mode: 'any', match: 'token' } }
        : { include: { terms: group.anchor.split(' '), mode: 'all', match: 'token' } };
      const { data: niche } = await api.post<{ id: string }>('/niches', {
        name: `Группа: ${group.anchor}`,
        description: 'Создана из автопредложения групп (растущие фразы); перед использованием проверьте состав «Расширить».',
        type: 'rule',
        rule: { filters },
      });
      setState('done');
      window.open(`/niches/${niche.id}?evaluate=${datasetId}`, '_self');
    } catch {
      setState('error');
    }
  }

  return (
    <button className="btn-primary text-xs" onClick={save} disabled={state === 'busy' || state === 'done'}>
      {state === 'done' ? 'сохранено' : state === 'busy' ? '…' : 'В нишу'}
    </button>
  );
}

function GroupMembersModal({
  group, datasetId, onClose,
}: {
  group: CandidateGroup;
  datasetId: string | null;
  onClose: () => void;
}) {
  const [expanded, setExpanded] = useState(false);
  const { data } = useQuery({
    queryKey: ['group-members', group.id, expanded],
    queryFn: async () =>
      (await api.get(`/candidate-groups/${group.id}/members`, {
        params: { dataset_id: datasetId, expand: expanded ? 1 : 0, limit: 100 },
      })).data as { data: { keyword_id: number; phrase_original: string; exact_current: number; smoothed_delta: number | null; is_new: boolean }[]; note: string },
  });

  return (
    <Modal open onClose={onClose} title={`Группа «${group.anchor}»`} wide>
      <div className="space-y-3">
        <p className="text-xs text-slate-500">{data?.note}</p>
        <div className="flex items-center justify-between">
          <p className="text-sm">
            Участников: <b>{fmtInt(group.member_count)}</b> · сумма точных: <b>{fmtInt(group.exact_sum_current)}</b>
          </p>
          <label className="flex items-center gap-2 text-sm">
            <input type="checkbox" className="h-4 w-4" checked={expanded} onChange={(e) => setExpanded(e.target.checked)} />
            Расширить на весь отчёт
          </label>
        </div>
        <div className="max-h-96 overflow-y-auto rounded border border-slate-200">
          <table className="w-full">
            <thead className="sticky top-0 bg-slate-50">
              <tr><th className="th">Фраза</th><th className="th text-right">Точная</th><th className="th text-right">Прирост A</th></tr>
            </thead>
            <tbody>
              {data?.data.map((m) => (
                <tr key={m.keyword_id} className="border-b border-slate-100">
                  <td className="td">
                    <Link target="_blank" rel="noopener" to={`/keywords/${m.keyword_id}?dataset_id=${datasetId}`} className="text-brand-700 hover:underline">
                      {m.phrase_original}
                    </Link>
                    {m.is_new && <Badge tone="violet">нов.</Badge>}
                  </td>
                  <td className="td text-right tabular-nums">{fmtInt(m.exact_current)}</td>
                  <td className="td text-right tabular-nums">{fmtInt(m.smoothed_delta)}</td>
                </tr>
              ))}
            </tbody>
          </table>
        </div>
      </div>
    </Modal>
  );
}
