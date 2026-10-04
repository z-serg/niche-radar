import { FormEvent, useRef, useState } from 'react';
import { useMutation, useQuery } from '@tanstack/react-query';
import { api, apiErrorMessage } from '../api/client';
import { useDatasets, useInvalidate } from '../api/hooks';
import { PageHeader } from '../components/Layout';
import { DATA_HELP } from '../lib/help';
import { Badge, EmptyState, ErrorNote, Modal, Spinner, StatusBadge } from '../components/ui';
import { COVERAGE_LABELS, fmtBytes, fmtInt, fmtSeconds } from '../lib/format';

export function DataPage() {
  const invalidate = useInvalidate();
  const { data: datasets, isLoading, error } = useDatasets();
  const [uploadError, setUploadError] = useState<string | null>(null);
  const [uploading, setUploading] = useState(false);
  const fileRef = useRef<HTMLInputElement>(null);
  const [mismatchDataset, setMismatchDataset] = useState<string | null>(null);
  const [newRevision, setNewRevision] = useState(false);
  const [dupNote, setDupNote] = useState<string | null>(null);
  const [deleteBlock, setDeleteBlock] = useState<{
    id: string;
    title: string;
    items: { kind: string; name: string; detail: string }[];
  } | null>(null);

  const upload = useMutation({
    mutationFn: async (file: File) => {
      const form = new FormData();
      form.append('file', file);
      form.append('coverage_type', 'unknown');
      if (newRevision) form.append('new_revision', '1');
      const { data } = await api.post('/datasets', form, {
        headers: { 'Content-Type': 'multipart/form-data' },
      });
      return data as { dataset_id: string; existing: boolean; status: string; preview: { mapping_valid: boolean } };
    },
    onSuccess: async (res) => {
      setDupNote(null);
      invalidate.datasets();
      if (res.existing) {
        // Дедупликация по SHA-256 (ТЗ §6.1 п.2): новый отчёт не создаётся —
        // это тот же источник. Объясняем явно и подсказываем путь к ревизии.
        setDupNote(
          'Файл байт-в-байт совпадает с уже принятым (SHA-256), поэтому новый отчёт не создавался: ' +
          'это тот же источник данных. Если нужна повторная обработка с другими настройками — ' +
          'отметьте «новая ревизия обработки» и загрузите файл снова.',
        );
        return;
      }
      // Сразу предлагаем импорт валидного файла.
      if (res.preview?.mapping_valid) {
        try {
          await api.post(`/datasets/${res.dataset_id}/import`);
          invalidate.datasets();
        } catch (e) {
          setUploadError(apiErrorMessage(e));
        }
      }
    },
    onError: (e) => setUploadError(apiErrorMessage(e)),
  });

  const action = useMutation({
    mutationFn: async ({ path, id }: { path: string; id: string }) => {
      if (path === 'import') return (await api.post(`/datasets/${id}/import`)).data;
      if (path === 'activate') return (await api.patch(`/datasets/${id}`, { is_active: true })).data;
      if (path === 'archive') return (await api.patch(`/datasets/${id}`, { is_archived: true })).data;
      if (path === 'cancel') return (await api.post(`/imports/${id}/cancel`)).data;
      if (path === 'retry') return (await api.post(`/imports/${id}/retry`)).data;
      if (path === 'delete') {
        const res = await api.delete(`/datasets/${id}`);
        return res.data;
      }
      if (path === 'delete_detach') {
        const res = await api.delete(`/datasets/${id}`, { params: { detach: 1 } });
        return res.data;
      }
      throw new Error('unknown action');
    },
    onSuccess: (_d, vars) => {
      invalidate.datasets();
      invalidate.dashboard();
      if (vars.path === 'delete') {
        // Проверка зависимостей проходит на сервере; при блокировке показываем.
      }
    },
    onError: (e, vars) => {
      if (vars.path === 'delete') {
        const data = (e as { response?: { data?: { message?: string; items?: { kind: string; name: string; detail: string }[] } } })?.response?.data;
        if (data?.items?.length) {
          const title = datasets?.find((d) => d.id === vars.id)?.title ?? vars.id;
          setDeleteBlock({ id: vars.id, title, items: data.items });
          return;
        }
        setUploadError(data?.message ?? apiErrorMessage(e));
      } else {
        setUploadError(apiErrorMessage(e));
      }
    },
  });

  function onSubmitUpload(e: FormEvent) {
    e.preventDefault();
    const file = fileRef.current?.files?.[0];
    if (!file) return;
    setUploadError(null);
    setDupNote(null);
    setUploading(true);
    upload.mutate(file, { onSettled: () => setUploading(false) });
  }

  function confirmDelete(id: string) {
    if (!window.confirm('Удалить отчёт? Данные, метрики и группы будут удалены безвозвратно.')) return;
    setDeleteBlock(null);
    action.mutate({ path: 'delete', id });
  }

  return (
    <>
      <PageHeader
        title="Данные"
        subtitle="отчёты Wordstat: загрузка, проверка, история импортов"
        help={DATA_HELP}
      />
      <div className="space-y-4 p-4">
        {uploadError && <ErrorNote message={uploadError} />}

        <form onSubmit={onSubmitUpload} className="card flex flex-wrap items-end gap-3 p-4">
          <div className="min-w-[320px] flex-1">
            <label className="label" htmlFor="csv-file">CSV-отчёт (до 5 ГБ; `;`, `,` или TAB; UTF-8)</label>
            <input
              id="csv-file" ref={fileRef} type="file" accept=".csv,text/csv,text/plain"
              className="input file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-2 file:py-1 file:text-xs"
            />
          </div>
          <button className="btn-primary" disabled={uploading}>
            {uploading || upload.isPending ? <><Spinner /> Загрузка…</> : 'Загрузить'}
          </button>
          <label className="flex items-center gap-1.5 pb-2 text-xs text-slate-600">
            <input
              type="checkbox" className="h-4 w-4 rounded border-slate-300"
              checked={newRevision} onChange={(e) => setNewRevision(e.target.checked)}
            />
            новая ревизия обработки того же файла
          </label>
          <p className="w-full text-[11px] text-slate-500">
            Одинаковый файл (SHA-256) не дублируется. Новый отчёт не становится активным, пока вы его не выберете.
          </p>
        </form>

        {dupNote && (
          <div className="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-900" role="status">
            {dupNote}
          </div>
        )}

        {isLoading && <div className="flex items-center gap-2 p-4 text-sm text-slate-500"><Spinner /> загрузка…</div>}
        {error && <ErrorNote message={apiErrorMessage(error)} />}

        {datasets && datasets.length === 0 && (
          <EmptyState
            title="Отчётов пока нет"
            hint="Загрузите CSV сверху или выполните ./bin/import /путь/к/файлу.csv из терминала."
          />
        )}

        <div className="space-y-3">
          {datasets?.map((d) => (
            <article key={d.id} className="card p-4">
              <div className="flex flex-wrap items-center justify-between gap-2">
                <div className="flex flex-wrap items-center gap-2">
                  <h3 className="font-semibold">{d.title}</h3>
                  <StatusBadge status={d.status} />
                  {d.is_active && <Badge tone="green">активный</Badge>}
                  <Badge>{COVERAGE_LABELS[d.coverage_type] ?? d.coverage_type}</Badge>
                  {d.revision > 1 && <Badge>ревизия {d.revision}</Badge>}
                  {d.quality_flags.map((f) => (
                    <Badge key={f} tone="amber" title={f}>{f}</Badge>
                  ))}
                </div>
                <DatasetActions
                  datasetId={d.id}
                  status={d.status}
                  isActive={d.is_active}
                  importStatus={d.import?.status ?? null}
                  onAction={(path, id) => action.mutate({ path, id })}
                  onDelete={confirmDelete}
                  onMismatch={() => setMismatchDataset(d.id)}
                />
              </div>

              <dl className="mt-2 grid grid-cols-3 gap-x-6 gap-y-1 text-xs text-slate-600 md:grid-cols-6">
                <div><dt className="text-slate-400">файл</dt><dd className="truncate" title={d.file.name}>{d.file.name}</dd></div>
                <div><dt className="text-slate-400">размер</dt><dd>{fmtBytes(d.file.size_bytes)}</dd></div>
                <div><dt className="text-slate-400">строк</dt><dd>{fmtInt(d.row_count)}</dd></div>
                <div><dt className="text-slate-400">замеров</dt><dd>{d.scan_count ?? '—'}</dd></div>
                <div><dt className="text-slate-400">загружен</dt><dd>{new Date(d.uploaded_at).toLocaleString('ru-RU')}</dd></div>
                <div><dt className="text-slate-400">SHA-256</dt><dd title={d.file.sha256} className="font-mono text-[10px]">{d.file.sha256.slice(0, 12)}…</dd></div>
              </dl>

              {d.import && ['queued', 'validating', 'importing', 'calculating', 'cancelling'].includes(d.import.status) && (
                <ImportProgressView progress={d.import.progress} stage={d.import.stage} status={d.import.status} />
              )}
              {d.import?.status === 'failed' && d.import.error && (
                <div className="mt-2 rounded border border-rose-200 bg-rose-50 p-2 text-xs text-rose-800">
                  {d.import.error}
                  {d.import.error.startsWith('CURRENT_COLUMNS_MISMATCH') && (
                    <div className="mt-2 flex items-center gap-2">
                      <select className="input w-56" id={`auth-${d.id}`} defaultValue="suffixed">
                        <option value="suffixed">основная: пронумерованная колонка</option>
                        <option value="plain">основная: ненумерованная колонка</option>
                      </select>
                      <button
                        className="btn-secondary"
                        onClick={async () => {
                          const select = document.getElementById(`auth-${d.id}`) as HTMLSelectElement;
                          await api.put(`/datasets/${d.id}/mapping`, {
                            mapping: (await api.get(`/datasets/${d.id}/preview`)).data.mapping,
                            policy: { authoritative_current: select.value },
                          });
                          await api.post(`/datasets/${d.id}/import`);
                          invalidate.datasets();
                        }}
                      >
                        Выбрать и перезапустить
                      </button>
                    </div>
                  )}
                </div>
              )}
            </article>
          ))}
        </div>

        {mismatchDataset && (
          <MappingModal
            datasetId={mismatchDataset}
            onClose={() => setMismatchDataset(null)}
            onSaved={() => { setMismatchDataset(null); invalidate.datasets(); }}
          />
        )}
      </div>

      {deleteBlock && (
        <Modal
          open
          onClose={() => setDeleteBlock(null)}
          title={`Удаление заблокировано: «${deleteBlock.title}»`}
        >
          <div className="space-y-3 text-sm">
            <p className="text-slate-600">
              На этот отчёт ссылаются пользовательские сущности (ТЗ §8 — удаление блокируется до их
              удаления или переноса):
            </p>
            <ul className="space-y-1.5">
              {deleteBlock.items.map((item, i) => (
                <li key={i} className="rounded border border-slate-200 bg-slate-50 px-3 py-2">
                  <span className="font-medium">
                    {item.kind === 'niche' ? 'Ниша' : item.kind === 'hypothesis' ? 'Гипотеза' : 'Ссылка'}: {item.name}
                  </span>
                  <span className="block text-xs text-slate-500">{item.detail}</span>
                </li>
              ))}
            </ul>
            <p className="text-xs text-slate-500">
              «Удалить, отвязав зависимости» — снимет ссылки (отчёт у гипотез, состав версий ниш),
              но сохранит сами ниши и гипотезы вместе с их сохранёнными снимками значений как историю.
            </p>
            <div className="flex justify-end gap-2 border-t border-slate-200 pt-3">
              <button className="btn-secondary" onClick={() => setDeleteBlock(null)}>Отменить</button>
              <button
                className="btn-danger"
                disabled={action.isPending}
                onClick={() => {
                  action.mutate({ path: 'delete_detach', id: deleteBlock.id });
                  setDeleteBlock(null);
                }}
              >
                Удалить отчёт, отвязав зависимости
              </button>
            </div>
          </div>
        </Modal>
      )}
    </>
  );
}

/**
 * Сопоставление колонок и выбор основной «текущей» колонки при расхождении
 * ненумерованной и суффиксной (ТЗ §4.2, §5.1).
 */
function MappingModal({
  datasetId, onClose, onSaved,
}: {
  datasetId: string;
  onClose: () => void;
  onSaved: () => void;
}) {
  const [preview, setPreview] = useState<{
    delimiter: string;
    headers: string[];
    mapping: { scans?: { ordinal: number; suffix: string | null }[]; problems?: string[] } | null;
    mapping_valid: boolean;
  } | null>(null);
  const [authoritative, setAuthoritative] = useState('suffixed');
  const [coverage, setCoverage] = useState('sample');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useQuery({
    queryKey: ['preview', datasetId],
    queryFn: async () => {
      const { data } = await api.get(`/datasets/${datasetId}/preview`);
      setPreview(data);
      return data;
    },
  });

  async function save() {
    if (!preview) return;
    setBusy(true);
    setError(null);
    try {
      await api.put(`/datasets/${datasetId}/mapping`, {
        mapping: preview.mapping,
        policy: { authoritative_current: authoritative },
        coverage_type: coverage,
      });
      await api.post(`/datasets/${datasetId}/import`);
      onSaved();
    } catch (e) {
      setError(apiErrorMessage(e));
    } finally {
      setBusy(false);
    }
  }

  return (
    <Modal open onClose={onClose} title="Сопоставление колонок отчёта" wide>
      {!preview ? (
        <div className="flex items-center gap-2 p-4 text-sm text-slate-500"><Spinner /> чтение заголовков…</div>
      ) : (
        <div className="space-y-4 text-sm">
          {error && <ErrorNote message={error} />}
          <div>
            <p className="mb-1 text-xs text-slate-500">
              Разделитель: <b>{preview.delimiter === ';' ? '«;»' : preview.delimiter === '\t' ? 'TAB' : '«,»'}</b> · колонок: {preview.headers.length}
              {preview.mapping?.scans ? ` · замеров распознано: ${preview.mapping.scans.length}` : ''}
            </p>
            <div className="max-h-44 overflow-y-auto rounded border border-slate-200 bg-slate-50 p-2">
              <ol className="grid grid-cols-2 gap-x-4 text-[11px] text-slate-700 md:grid-cols-3">
                {preview.headers.map((h, i) => <li key={i} className="font-mono">{i + 1}. {h}</li>)}
              </ol>
            </div>
          </div>

          <div className="grid grid-cols-2 gap-3">
            <div>
              <label className="label" htmlFor="map-auth">Основная «текущая» колонка</label>
              <select id="map-auth" className="input" value={authoritative} onChange={(e) => setAuthoritative(e.target.value)}>
                <option value="suffixed">пронумерованная (суффикс 1)</option>
                <option value="plain">ненумерованная («Частотность…»)</option>
                <option value="auto">авто: блокировать при расхождении</option>
              </select>
            </div>
            <div>
              <label className="label" htmlFor="map-cov">Тип охвата</label>
              <select id="map-cov" className="input" value={coverage} onChange={(e) => setCoverage(e.target.value)}>
                <option value="unknown">неизвестен</option>
                <option value="full_top">полный топ</option>
                <option value="sample">выборка</option>
                <option value="filtered">отфильтрованная выборка</option>
              </select>
            </div>
          </div>

          <div className="flex justify-end gap-2 border-t border-slate-200 pt-3">
            <button className="btn-secondary" onClick={onClose}>Отмена</button>
            <button className="btn-primary" onClick={save} disabled={busy}>
              {busy ? <><Spinner /> сохранение…</> : 'Сохранить и импортировать'}
            </button>
          </div>
        </div>
      )}
    </Modal>
  );
}

function DatasetActions({
  datasetId, status, isActive, importStatus, onAction, onDelete, onMismatch,
}: {
  datasetId: string;
  status: string;
  isActive: boolean;
  importStatus: string | null;
  onAction: (path: string, id: string) => void;
  onDelete: (id: string) => void;
  onMismatch: () => void;
}) {
  const busyImport = importStatus && ['queued', 'validating', 'importing', 'calculating'].includes(importStatus);
  return (
    <div className="flex flex-wrap items-center gap-2">
      {status === 'ready' && !isActive && (
        <button className="btn-secondary" onClick={() => onAction('activate', datasetId)}>Сделать активным</button>
      )}
      {status === 'needs_mapping' && (
        <>
          <button className="btn-secondary" onClick={onMismatch}>Сопоставление…</button>
        </>
      )}
      {['uploaded', 'failed', 'cancelled', 'ready'].includes(status) && !busyImport && (
        <button className="btn-secondary" onClick={() => onAction('import', datasetId)}>
          {status === 'ready' ? 'Перерасчитать' : 'Запустить импорт'}
        </button>
      )}
      {busyImport && (
        <button className="btn-danger" onClick={() => onAction('cancel', datasetId)}>Отменить</button>
      )}
      {importStatus === 'failed' && (
        <button className="btn-secondary" onClick={() => onAction('retry', datasetId)}>Повторить</button>
      )}
      <button className="btn-danger" onClick={() => onDelete(datasetId)}>Удалить</button>
    </div>
  );
}

function ImportProgressView({
  progress, stage, status,
}: {
  progress: { pct?: number; rows?: number; total_bytes?: number; bytes?: number; rows_per_sec?: number; eta_sec?: number } | null;
  stage: string;
  status: string;
}) {
  const stageLabels: Record<string, string> = {
    queued: 'в очереди', validating: 'проверка структуры', importing: 'чтение и разгрузка',
    loading: 'загрузка в базу', calculating: 'расчёт метрик', done: 'готово',
    cancelling: 'отмена…', cancelled: 'отменён',
  };
  const pct = progress?.pct ?? 0;

  return (
    <div className="mt-3 space-y-1.5">
      <div className="flex items-center justify-between text-xs text-slate-600">
        <span className="flex items-center gap-2"><Spinner className="h-3 w-3" /> {stageLabels[stage] ?? stage} ({status})</span>
        <span className="tabular-nums">
          {progress?.rows ? `${fmtInt(progress.rows)} строк · ` : ''}
          {progress?.rows_per_sec ? `${fmtInt(progress.rows_per_sec)} строк/с · ` : ''}
          {progress?.eta_sec ? `осталось ~${fmtSeconds(progress.eta_sec)}` : ''}
        </span>
      </div>
      <div className="h-1.5 overflow-hidden rounded bg-slate-100">
        <div className="h-full rounded bg-brand-500 transition-all" style={{ width: `${pct}%` }} />
      </div>
    </div>
  );
}

