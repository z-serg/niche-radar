import { Link } from 'react-router-dom';
import { api, apiErrorMessage } from '../api/client';
import { useDatasets, useInvalidate } from '../api/hooks';
import { useState } from 'react';
import { ErrorNote } from './ui';

/**
 * Подсказка вместо пустого дашборда/исследования, когда нет активного
 * отчёта: объясняет, что происходит с загруженным источником и какое
 * действие доступно (активация — явный выбор владельца, ТЗ §5.1).
 */
export function NoActiveDatasetHint() {
  const { data: datasets } = useDatasets();
  const invalidate = useInvalidate();
  const [error, setError] = useState<string | null>(null);
  const [busyId, setBusyId] = useState<string | null>(null);

  const processing = datasets?.filter((d) => ['uploaded', 'queued', 'validating', 'importing', 'calculating', 'needs_mapping'].includes(d.status)) ?? [];
  const ready = datasets?.filter((d) => d.status === 'ready') ?? [];

  async function activate(id: string) {
    setBusyId(id);
    setError(null);
    try {
      await api.patch(`/datasets/${id}`, { is_active: true });
      invalidate.datasets();
      invalidate.dashboard();
      window.location.reload();
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusyId(null);
    }
  }

  return (
    <div className="space-y-3">
      {error && <ErrorNote message={error} />}

      {processing.length > 0 && (
        <div className="rounded border border-blue-200 bg-blue-50 p-4 text-sm text-blue-900">
          <p className="font-medium">
            Источник{processing.length > 1 ? 'ы' : ''} обрабатывается: {processing.map((d) => `«${d.title}» (${d.import?.stage ?? d.status})`).join(', ')}.
          </p>
          <p className="mt-1 text-xs">
            Импорт 3 млн строк занимает ~13 минут; прогресс виден в разделе «Данные». Когда статус
            станет «готов», отчёт нужно сделать активным — после этого данные появятся здесь автоматически.
          </p>
          <Link to="/data" className="btn-secondary mt-2 text-xs">Перейти в «Данные»</Link>
        </div>
      )}

      {ready.length > 0 && (
        <div className="rounded border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-900">
          <p className="font-medium">
            Готов{ready.length > 1 ? 'ые отчёты не активированы' : 'ый отчёт не активирован'}: {ready.map((d) => `«${d.title}»`).join(', ')}.
          </p>
          <p className="mt-1 text-xs">
            Новый отчёт не становится активным автоматически — это явный выбор владельца.
          </p>
          <div className="mt-2 flex flex-wrap gap-2">
            {ready.map((d) => (
              <button key={d.id} className="btn-primary text-xs" disabled={busyId === d.id} onClick={() => activate(d.id)}>
                {busyId === d.id ? 'Активация…' : `Сделать «${d.title}» активным`}
              </button>
            ))}
          </div>
        </div>
      )}

      {processing.length === 0 && ready.length === 0 && (
        <div className="rounded border border-dashed border-slate-300 bg-white p-8 text-center">
          <p className="text-sm font-medium text-slate-700">Нет загруженных отчётов</p>
          <p className="mx-auto mt-1 max-w-md text-xs text-slate-500">
            Загрузите CSV-отчёт Wordstat в разделе «Данные» (или выполните{' '}
            <code className="rounded bg-slate-100 px-1 font-mono">./bin/import /путь/к/файлу.csv</code>),
            дождитесь статуса «готов» и сделайте отчёт активным.
          </p>
          <Link to="/data" className="btn-primary mt-3 text-xs">Загрузить отчёт</Link>
        </div>
      )}
    </div>
  );
}
