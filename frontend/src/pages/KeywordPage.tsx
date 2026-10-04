import { useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import clsx from 'clsx';
import { api, apiErrorMessage } from '../api/client';
import { useKeyword } from '../api/hooks';
import { PageHeader } from '../components/Layout';
import { KEYWORD_HELP } from '../lib/help';
import { Badge, EmptyState, Spinner } from '../components/ui';
import { HistoryChart } from '../components/charts';
import {
  FLAG_LABELS, NULL_REASON_LABELS, TASK_CATEGORY_LABELS, delta, fmtInt, fmtNum, fmtPct,
} from '../lib/format';

export function KeywordPage() {
  const { id } = useParams();
  const [params] = useSearchParams();
  const datasetId = params.get('dataset_id');
  const { data: kw, isLoading, error } = useKeyword(id ? Number(id) : null, datasetId);
  const [noteText, setNoteText] = useState('');
  const [labelText, setLabelText] = useState('');
  const [savedMsg, setSavedMsg] = useState('');

  if (isLoading) {
    return <><PageHeader title="Карточка фразы" help={KEYWORD_HELP} /><div className="p-6 flex items-center gap-2 text-sm text-slate-500"><Spinner /> загрузка…</div></>;
  }
  if (error || !kw) {
    return <><PageHeader title="Карточка фразы" help={KEYWORD_HELP} /><div className="p-6"><EmptyState title="Фраза не найдена" hint={apiErrorMessage(error)} /></div></>;
  }

  const m = kw.metrics;
  const d = delta(m?.smoothed_delta ?? null);
  const nullReasons = Object.entries(m?.null_reasons ?? {});
  // В карточке флаги приходят внутри metrics; в строках поиска — на верхнем уровне.
  const flags = m?.flags ?? kw.flags ?? [];

  async function addNote() {
    if (!noteText.trim()) return;
    await api.post(`/keywords/${id}/notes`, { body: noteText.trim() });
    setNoteText('');
    setSavedMsg('Заметка добавлена.');
  }
  async function addLabel() {
    if (!labelText.trim()) return;
    await api.post(`/keywords/${id}/labels`, { label: labelText.trim() });
    setLabelText('');
    setSavedMsg('Метка добавлена.');
  }

  return (
    <>
      <PageHeader
        title={kw.phrase}
        help={KEYWORD_HELP}
        subtitle={<>фраза #{kw.keyword_id} · <Link to="/explore" className="text-brand-700 hover:underline">к исследованию</Link></>}
      />
      <div className="grid grid-cols-[minmax(0,1fr)_360px] gap-4 p-4">
        <div className="space-y-4">
          {/* История: раздельные серии, пропуски не соединяются */}
          <section className="card p-4">
            <h2 className="mb-2 text-sm font-semibold">История частотностей</h2>
            <HistoryChart history={kw.history} />
            <p className="mt-2 text-[11px] text-slate-500">
              Ось порядковая: замеры от старого (1) к новому ({kw.history.length}). Даты сканирований неизвестны —
              календарные и месячные темпы не рассчитываются. Штриховая линия — широкая частотность (не суммируется как рынок).
            </p>
          </section>

          {/* Метрики и формула приоритета */}
          <section className="card space-y-3 p-4">
            <h2 className="text-sm font-semibold">Метрики ({m?.formula_version ?? '—'}, правила {m?.rules_version ?? '—'})</h2>
            <div className="overflow-x-auto">
              <table className="w-full text-sm">
                <tbody>
                  {([
                    ['Точная текущая (fn)', fmtInt(m?.exact_current ?? null)],
                    ['Широкая текущая', fmtInt(m?.broad_current ?? null)],
                    ['Первая точная (f1)', fmtInt(m?.exact_first ?? null)],
                    ['Прирост границ fn − f1', fmtInt(m?.exact_delta ?? null)],
                    ['Рост границ, %', fmtPct(m?.growth_pct ?? null)],
                    ['Ранняя база B = (f1+f2)/2', fmtNum(m?.base_avg ?? null, 1)],
                    ['Поздний уровень R = (f[n−1]+fn)/2', fmtNum(m?.late_avg ?? null, 1)],
                    ['Сглаженный прирост A = R − B', fmtNum(m?.smoothed_delta ?? null, 1)],
                    ['Сглаженный рост G = (R−B)/B', fmtPct(m?.smoothed_growth ?? null)],
                    ['Устойчивость C', fmtNum(m?.consistency ?? null, 2)],
                    ['Сохранение пика P', fmtNum(m?.peak_retention ?? null, 2)],
                    ['Признак задачи T (макс. вес)', fmtNum(m?.task_score ?? null, 2)],
                  ] as [string, string][]).map(([k, v]) => (
                    <tr key={k} className="border-b border-slate-100 last:border-0">
                      <td className="py-1 pr-4 text-slate-600">{k}</td>
                      <td className="py-1 text-right font-medium tabular-nums">{v}</td>
                    </tr>
                  ))}
                </tbody>
              </table>
            </div>

            <div className="rounded bg-slate-50 p-3 text-xs">
              <p className="font-medium text-slate-700">Приоритет исследования: {m?.priority ?? 'н/д'}</p>
              <p className="mt-1 font-mono text-[10px] leading-relaxed text-slate-500">
                priority = round(100 × (0.30·g + 0.25·a + 0.20·C + 0.15·v + 0.10·T)); g=clip(G/2), a=clip(ln(1+A)/ln(10001)), v=clip(ln(1+fn)/ln(100001))
              </p>
              {m?.priority_components && (
                <p className="mt-1 text-slate-500">
                  компоненты: g={fmtNum(m.priority_components.g, 3)}, a={fmtNum(m.priority_components.a, 3)},
                  C={fmtNum(m.priority_components.c, 2)}, v={fmtNum(m.priority_components.v, 3)}, T={fmtNum(m.priority_components.t, 2)}
                </p>
              )}
              {nullReasons.length > 0 && (
                <p className="mt-1 text-amber-700">
                  Причины NULL: {nullReasons.map(([k, v]) => `${k} — ${NULL_REASON_LABELS[v] ?? v}`).join('; ')}
                </p>
              )}
            </div>
          </section>

          {/* Похожие фразы */}
          <section className="card space-y-3 p-4">
            <h2 className="text-sm font-semibold">Похожие фразы</h2>
            {kw.similar.map((group) => (
              <div key={group.method}>
                <p className="mb-1 text-[11px] font-medium uppercase tracking-wide text-slate-400">поиск: {group.method}</p>
                {group.items.length === 0 ? (
                  <p className="text-xs text-slate-400">нет совпадений</p>
                ) : (
                  <ul className="flex flex-wrap gap-1.5">
                    {group.items.map((item) => (
                      <li key={item.id}>
                        <Link
                          target="_blank"
                          rel="noopener"
                          to={`/keywords/${item.id}?dataset_id=${datasetId}`}
                          className="inline-flex items-center gap-1 rounded border border-slate-200 bg-slate-50 px-2 py-0.5 text-xs hover:border-brand-300 hover:bg-brand-50"
                        >
                          {item.phrase}
                          {item.shared_tokens !== undefined && <span className="text-slate-400">· {item.shared_tokens} об. токена</span>}
                          {item.similarity !== undefined && <span className="text-slate-400">· {fmtNum(item.similarity, 2)}</span>}
                        </Link>
                      </li>
                    ))}
                  </ul>
                )}
              </div>
            ))}
          </section>
        </div>

        {/* Правая колонка: ранги, ниши, заметки */}
        <div className="space-y-4">
          <section className="card space-y-2 p-4 text-sm">
            <h2 className="text-sm font-semibold">Ранги и источник</h2>
            <p>Позиция текущая: <b>{fmtInt(kw.rank_current)}</b></p>
            <p>
              Предыдущая позиция:{' '}
              {kw.rank_previous === 0
                ? <Badge tone="violet">не было в прошлом рейтинге</Badge>
                : <b>{fmtInt(kw.rank_previous)}</b>}
            </p>
            <p className="text-xs text-slate-500">
              Слов (вычислено): {kw.word_count} · символов: {kw.char_count}
              {kw.source_word_count !== null && ` · источник: ${kw.source_word_count} слов / ${kw.source_char_count} симв.`}
            </p>
            <div className="flex flex-wrap gap-1 pt-1">
              {flags.map((f) => <Badge key={f} tone="amber">{FLAG_LABELS[f] ?? f}</Badge>)}
              {m?.task_categories.map((c) => (
                <Badge key={c} tone={c === 'weak' ? 'slate' : 'blue'}>{TASK_CATEGORY_LABELS[c] ?? c}</Badge>
              ))}
            </div>
            {kw.rank_previous_note && <p className="text-xs text-violet-700">{kw.rank_previous_note}</p>}
          </section>

          <section className="card space-y-2 p-4 text-sm">
            <h2 className="text-sm font-semibold">Ниши с этой фразой</h2>
            {kw.niches.length === 0 ? (
              <p className="text-xs text-slate-400">фраза не входит в ниши</p>
            ) : (
              <ul className="space-y-1">
                {kw.niches.map((n) => (
                  <li key={n.id}>
                    <Link to={`/niches/${n.id}`} className="text-brand-700 hover:underline">{n.name}</Link>
                    <span className="text-xs text-slate-400"> · версия {n.version}</span>
                  </li>
                ))}
              </ul>
            )}
          </section>

          <section className="card space-y-3 p-4 text-sm">
            <h2 className="text-sm font-semibold">Метки и заметки владельца</h2>
            <div className="flex flex-wrap gap-1">
              {kw.labels.filter((l) => l.origin === 'manual').map((l) => (
                <Badge key={l.label} tone="blue">{l.label}</Badge>
              ))}
              {kw.labels.filter((l) => l.origin === 'rule').map((l) => (
                <Badge key={l.label} tone="slate" title={`правило ${l.rule_version}`}>
                  {(TASK_CATEGORY_LABELS[l.label] ?? l.label) + ' (правило)'}
                </Badge>
              ))}
            </div>
            <div className="flex gap-2">
              <input className="input" placeholder="своя метка…" value={labelText} onChange={(e) => setLabelText(e.target.value)} />
              <button className="btn-secondary" onClick={addLabel}>+</button>
            </div>
            <div className="flex gap-2">
              <input className="input" placeholder="заметка…" value={noteText} onChange={(e) => setNoteText(e.target.value)} />
              <button className="btn-secondary" onClick={addNote}>+</button>
            </div>
            {savedMsg && <p className="text-xs text-emerald-700">{savedMsg}</p>}
            <ul className="space-y-1 text-xs text-slate-600">
              {kw.notes.map((n) => (
                <li key={n.id} className="rounded border border-slate-100 bg-slate-50 p-2">{n.body}</li>
              ))}
            </ul>
          </section>

          <section className="card p-4">
            <h2 className="mb-1 text-sm font-semibold">Сглаженный прирост</h2>
            <p className={clsx('text-2xl font-bold tabular-nums', d.cls)}>{d.text}</p>
            <p className="text-[11px] text-slate-500">Изменение позиции не заменяет изменение частотности.</p>
          </section>
        </div>
      </div>
    </>
  );
}
