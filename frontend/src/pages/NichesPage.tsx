import { FormEvent, useEffect, useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { api, apiErrorMessage } from '../api/client';
import { useInvalidate, useNiches } from '../api/hooks';
import { PageHeader } from '../components/Layout';
import { NICHES_HELP } from '../lib/help';
import { Badge, EmptyState, ErrorNote, Modal, Spinner } from '../components/ui';
import { fmtInt } from '../lib/format';

export function NichesPage() {
  const { data: niches, isLoading, error } = useNiches();
  const [createOpen, setCreateOpen] = useState(false);
  const [params] = useSearchParams();
  const navigate = useNavigate();

  useEffect(() => {
    if (params.get('create') === 'from-selection' && !createOpen) {
      setCreateOpen(true);
    }
  }, [params, createOpen]);

  return (
    <>
      <PageHeader
        title="Ниши"
        subtitle="сохранённые подборки фраз: фиксированный список или правило поиска"
        help={NICHES_HELP}
        actions={<button className="btn-primary" onClick={() => setCreateOpen(true)}>Создать нишу</button>}
      />
      <div className="p-4">
        {isLoading && <div className="flex items-center gap-2 p-4 text-sm text-slate-500"><Spinner /> загрузка…</div>}
        {error && <ErrorNote message={apiErrorMessage(error)} />}

        {niches && niches.length === 0 && (
          <EmptyState
            title="Ниш пока нет"
            hint="Выберите фразы в «Исследовании» или сохраните группу из «Кандидатов»."
          />
        )}

        <div className="grid grid-cols-2 gap-3 xl:grid-cols-3">
          {niches?.map((n) => (
            <Link key={n.id} to={`/niches/${n.id}`} className="card block space-y-1.5 p-4 hover:border-brand-300">
              <div className="flex items-center justify-between gap-2">
                <h3 className="truncate font-semibold">{n.name}</h3>
                <Badge tone={n.type === 'fixed' ? 'slate' : 'blue'}>{n.type === 'fixed' ? 'фиксированная' : 'правило'}</Badge>
              </div>
              <p className="line-clamp-2 text-xs text-slate-500">{n.description ?? 'без описания'}</p>
              <p className="text-xs text-slate-400">
                версия: {n.last_version ?? 'нет'} · фраз в списке: {n.manual_include_count ?? 0}
              </p>
            </Link>
          ))}
        </div>
      </div>

      {createOpen && (
        <CreateNicheModal
          onClose={() => { setCreateOpen(false); navigate('/niches'); }}
        />
      )}
    </>
  );
}

function CreateNicheModal({ onClose }: { onClose: () => void }) {
  const invalidate = useInvalidate();
  const preselected = (() => {
    try {
      return JSON.parse(window.sessionStorage.getItem('niche-candidates') ?? '[]') as { id: number; phrase: string }[];
    } catch {
      return [];
    }
  })();
  const [name, setName] = useState('');
  const [description, setDescription] = useState('');
  const [type, setType] = useState<'fixed' | 'rule'>(preselected.length ? 'fixed' : 'rule');
  const [ruleText, setRuleText] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function submit(e: FormEvent) {
    e.preventDefault();
    setBusy(true);
    setError(null);
    try {
      const rule = type === 'rule' && ruleText.trim()
        ? { filters: { include: { terms: ruleText.split(/[\s,;]+/).filter(Boolean), mode: 'any', match: 'token' } } }
        : null;
      const { data } = await api.post<{ id: string }>('/niches', {
        name,
        description: description || null,
        type,
        rule,
        manual_include: preselected.map((p) => p.id),
      });
      invalidate.niches();
      window.sessionStorage.removeItem('niche-candidates');
      window.location.href = `/niches/${data.id}`;
    } catch (err) {
      setError(apiErrorMessage(err));
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title="Новая ниша">
      <form onSubmit={submit} className="space-y-3 text-sm">
        {error && <ErrorNote message={error} />}
        {preselected.length > 0 && (
          <p className="rounded border border-brand-200 bg-brand-50 p-2 text-xs text-brand-800">
            Из исследования выбрано фраз: {preselected.length}. Они войдут в фиксированный состав ниши.
          </p>
        )}
        <div>
          <label className="label" htmlFor="niche-name">Название</label>
          <input id="niche-name" className="input" value={name} onChange={(e) => setName(e.target.value)} required />
        </div>
        <div>
          <label className="label" htmlFor="niche-desc">Описание задачи</label>
          <textarea id="niche-desc" className="input min-h-16" value={description} onChange={(e) => setDescription(e.target.value)} />
        </div>
        <div>
          <label className="label" htmlFor="niche-type">Тип состава</label>
          <select id="niche-type" className="input" value={type} onChange={(e) => setType(e.target.value as 'fixed' | 'rule')}>
            <option value="fixed">фиксированный список ({preselected.length} выбранных фраз)</option>
            <option value="rule">правило поиска</option>
          </select>
        </div>
        {type === 'rule' && (
          <div>
            <label className="label" htmlFor="niche-rule">Слова правила (через запятую)</label>
            <input id="niche-rule" className="input" value={ruleText} onChange={(e) => setRuleText(e.target.value)} placeholder="конвертер, pdf" />
          </div>
        )}
        <div className="flex justify-end gap-2 border-t border-slate-200 pt-3">
          <button type="button" className="btn-secondary" onClick={onClose}>Отмена</button>
          <button className="btn-primary" disabled={busy}>{busy ? 'Сохранение…' : 'Создать'}</button>
        </div>
      </form>
    </Modal>
  );
}
