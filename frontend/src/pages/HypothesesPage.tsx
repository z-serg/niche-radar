import { FormEvent, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../api/client';
import { useHypothesis, useHypotheses, useInvalidate, useNiches } from '../api/hooks';
import { PageHeader } from '../components/Layout';
import { HYPOTHESES_HELP, HYPOTHESIS_HELP } from '../lib/help';
import { Badge, EmptyState, ErrorNote, Spinner } from '../components/ui';
import { fmtInt, fmtPct } from '../lib/format';

const STATUS_OPTIONS = [
  ['candidate', 'кандидат'],
  ['researching', 'исследую'],
  ['testing', 'проверяю'],
  ['building', 'в разработке'],
  ['parked', 'отложено'],
  ['rejected', 'отклонено'],
] as const;

const STATUS_TONES: Record<string, 'slate' | 'blue' | 'green' | 'amber' | 'red'> = {
  candidate: 'slate', researching: 'blue', testing: 'amber', building: 'green', parked: 'slate', rejected: 'red',
};

export function HypothesesPage() {
  const [status, setStatus] = useState('');
  const { data, isLoading } = useHypotheses(status || undefined);

  return (
    <>
      <PageHeader
        title="Гипотезы"
        subtitle="продуктовые гипотезы с подтверждающими фразами"
        help={HYPOTHESES_HELP}
        actions={<Link to="/hypotheses/new" className="btn-primary">Новая гипотеза</Link>}
      />
      <div className="space-y-3 p-4">
        <div className="flex flex-wrap items-center gap-2">
          <button className={status === '' ? 'btn-primary' : 'btn-secondary'} onClick={() => setStatus('')}>все</button>
          {STATUS_OPTIONS.map(([key, label]) => (
            <button key={key} className={status === key ? 'btn-primary' : 'btn-secondary'} onClick={() => setStatus(key)}>
              {label}
            </button>
          ))}
        </div>

        {isLoading && <div className="flex items-center gap-2 p-4 text-sm text-slate-500"><Spinner /> загрузка…</div>}
        {data?.length === 0 && <EmptyState title="Гипотез нет" hint="Создайте первую гипотезу из ниши или результатов исследования." />}

        <div className="space-y-2">
          {data?.map((h) => (
            <Link key={h.id} to={`/hypotheses/${h.id}`} className="card flex items-center justify-between gap-3 p-4 hover:border-brand-300">
              <div className="min-w-0">
                <h3 className="truncate font-semibold">{h.title}</h3>
                <p className="text-xs text-slate-500">
                  {h.niche_name ? `ниша: ${h.niche_name} · ` : ''}обновлена {new Date(h.updated_at).toLocaleDateString('ru-RU')}
                </p>
              </div>
              <Badge tone={STATUS_TONES[h.status] ?? 'slate'}>{h.status_label}</Badge>
            </Link>
          ))}
        </div>
      </div>
    </>
  );
}

interface FormState {
  title: string;
  niche_id: string;
  audience: string;
  problem: string;
  current_solution: string;
  product_idea: string;
  mvp: string;
  monetization: string;
  notes: string;
  research_links: string;
  next_experiment: string;
  success_criterion: string;
  status: string;
  status_reason: string;
}

const EMPTY: FormState = {
  title: '', niche_id: '', audience: '', problem: '', current_solution: '', product_idea: '',
  mvp: '', monetization: '', notes: '', research_links: '', next_experiment: '', success_criterion: '',
  status: 'candidate', status_reason: '',
};

export function HypothesisPage() {
  const { id } = useParams();
  const isNew = !id || id === 'new';
  const navigate = useNavigate();
  const invalidate = useInvalidate();
  const { data: hypothesis, isLoading } = useHypothesis(isNew ? null : id ?? null);
  const { data: niches } = useNiches();
  const [form, setForm] = useState<FormState>(EMPTY);
  const [loadedId, setLoadedId] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  if (!isNew && hypothesis && loadedId !== hypothesis.id) {
    setLoadedId(hypothesis.id);
    setForm({
      title: hypothesis.title, niche_id: hypothesis.niche_id ?? '', audience: hypothesis.audience ?? '',
      problem: hypothesis.problem ?? '', current_solution: hypothesis.current_solution ?? '',
      product_idea: hypothesis.product_idea ?? '', mvp: hypothesis.mvp ?? '',
      monetization: hypothesis.monetization ?? '', notes: hypothesis.notes ?? '',
      research_links: hypothesis.research_links ?? '', next_experiment: hypothesis.next_experiment ?? '',
      success_criterion: hypothesis.success_criterion ?? '', status: hypothesis.status,
      status_reason: hypothesis.status_reason ?? '',
    });
  }

  const evidence = !isNew && hypothesis ? 'evidence' in hypothesis ? (hypothesis as { evidence: Evidence[] }).evidence : [] : [];

  function set<K extends keyof FormState>(key: K, value: string) {
    setForm((f) => ({ ...f, [key]: value }));
  }

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    const payload = {
      ...form,
      niche_id: form.niche_id || null,
    };
    try {
      if (isNew) {
        const { data } = await api.post<{ id: string }>('/hypotheses', payload);
        invalidate.hypotheses();
        navigate(`/hypotheses/${data.id}`);
      } else {
        await api.patch(`/hypotheses/${id}`, payload);
        invalidate.hypotheses();
        window.location.reload();
      }
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  async function exportMd() {
    await api.post(`/hypotheses/${id}/export`);
  }

  async function deleteHypothesis() {
    if (!window.confirm(`Удалить гипотезу «${hypothesis?.title ?? ''}» с доказательствами? Действие необратимо.`)) return;
    try {
      await api.delete(`/hypotheses/${id}`);
      invalidate.hypotheses();
      window.location.href = '/hypotheses';
    } catch (e) {
      setError(apiErrorMessage(e));
    }
  }

  if (!isNew && isLoading) {
    return <><PageHeader title="Гипотеза" help={HYPOTHESIS_HELP} /><div className="p-6 flex items-center gap-2 text-sm text-slate-500"><Spinner /> загрузка…</div></>;
  }

  const fields: [keyof FormState, string, boolean?][] = [
    ['audience', 'Целевая аудитория (гипотеза владельца)'],
    ['problem', 'Задача пользователей'],
    ['current_solution', 'Существующий способ решения'],
    ['product_idea', 'Идея онлайн-сервиса'],
    ['mvp', 'Минимальная полезная функция'],
    ['monetization', 'Предположение о монетизации (не данные CSV)'],
    ['next_experiment', 'Следующий эксперимент'],
    ['success_criterion', 'Критерий результата'],
    ['research_links', 'Ссылки для дальнейшего исследования'],
    ['notes', 'Заметки'],
  ];

  return (
    <>
      <PageHeader
        title={isNew ? 'Новая гипотеза' : (hypothesis?.title ?? 'Гипотеза')}
        help={HYPOTHESIS_HELP}
        actions={!isNew && (
          <>
            <button className="btn-secondary" onClick={exportMd}>Экспорт Markdown</button>
            <button className="btn-danger" onClick={deleteHypothesis}>Удалить</button>
          </>
        )}
      />
      <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4 p-4">
        <form onSubmit={submit} className="card space-y-3 p-5">
          {error && <ErrorNote message={error} />}

          <div>
            <label className="label" htmlFor="h-title">Название</label>
            <input id="h-title" className="input" value={form.title} onChange={(e) => set('title', e.target.value)} required />
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label" htmlFor="h-niche">Связанная ниша</label>
              <select id="h-niche" className="input" value={form.niche_id} onChange={(e) => set('niche_id', e.target.value)}>
                <option value="">— без ниши —</option>
                {niches?.map((n) => <option key={n.id} value={n.id}>{n.name}</option>)}
              </select>
            </div>
            <div>
              <label className="label" htmlFor="h-status">Статус</label>
              <select id="h-status" className="input" value={form.status} onChange={(e) => set('status', e.target.value)}>
                {STATUS_OPTIONS.map(([key, label]) => <option key={key} value={key}>{label}</option>)}
              </select>
            </div>
          </div>

          {['parked', 'rejected'].includes(form.status) && (
            <div>
              <label className="label" htmlFor="h-reason">Причина отклонения/откладывания</label>
              <input id="h-reason" className="input" value={form.status_reason} onChange={(e) => set('status_reason', e.target.value)} />
            </div>
          )}

          {fields.map(([key, label]) => (
            <div key={key}>
              <label className="label" htmlFor={`h-${key}`}>{label}</label>
              <textarea
                id={`h-${key}`}
                className="input min-h-14"
                value={form[key]}
                onChange={(e) => set(key, e.target.value)}
              />
            </div>
          ))}

          <div className="flex justify-end gap-2 border-t border-slate-200 pt-3">
            <button type="button" className="btn-secondary" onClick={() => navigate('/hypotheses')}>Закрыть</button>
            <button className="btn-primary" disabled={busy}>{busy ? 'Сохранение…' : 'Сохранить'}</button>
          </div>
        </form>

        {/* Доказательства */}
        <aside className="card space-y-2 p-4">
          <h2 className="text-sm font-semibold">Подтверждающие фразы</h2>
          <p className="text-[11px] text-slate-500">
            Добавляются из карточки фразы или исследования; значения фиксируются на момент добавления
            (сохранённая оценка) и не пересчитываются автоматически.
          </p>
          {evidence.length === 0 ? (
            <p className="text-xs text-slate-400">пока нет доказательств</p>
          ) : (
            <ul className="space-y-2">
              {evidence.map((ev) => {
                const m = (ev.snapshot as { metrics?: { exact_current?: number; smoothed_delta?: number; priority?: number } })?.metrics;
                return (
                  <li key={ev.id} className="rounded border border-slate-100 p-2 text-xs">
                    <div className="flex items-center justify-between gap-2">
                      <Link target="_blank" rel="noopener" to={`/keywords/${ev.keyword_id}`} className="truncate font-medium text-brand-700 hover:underline">{ev.phrase}</Link>
                      <button
                        className="text-rose-500 hover:underline"
                        onClick={async () => {
                          await api.delete(`/hypotheses/${id}/evidence/${ev.keyword_id}`);
                          window.location.reload();
                        }}
                      >
                        убрать
                      </button>
                    </div>
                    <p className="mt-0.5 text-slate-500">
                      точная: {fmtInt(m?.exact_current ?? null)} · A: {fmtInt(m?.smoothed_delta ?? null)} · приоритет: {m?.priority ?? '—'}
                    </p>
                  </li>
                );
              })}
            </ul>
          )}
        </aside>
      </div>
    </>
  );
}

interface Evidence {
  id: number;
  keyword_id: number;
  phrase: string;
  snapshot: Record<string, unknown>;
}

export function hypothesisEvidenceSummary(ev: Evidence): string {
  const m = (ev.snapshot as { metrics?: { growth_pct?: number } })?.metrics;
  return m?.growth_pct !== undefined ? fmtPct(m.growth_pct) : '—';
}
