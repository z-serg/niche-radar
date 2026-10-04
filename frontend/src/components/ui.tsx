import { ReactNode } from 'react';
import { createPortal } from 'react-dom';
import clsx from 'clsx';

export function Spinner({ className }: { className?: string }) {
  return (
    <span
      className={clsx('inline-block h-4 w-4 animate-spin rounded-full border-2 border-slate-300 border-t-brand-600', className)}
      aria-label="загрузка"
    />
  );
}

export function Badge({
  children,
  tone = 'slate',
  title,
}: {
  children: ReactNode;
  tone?: 'slate' | 'green' | 'red' | 'amber' | 'blue' | 'violet';
  title?: string;
}) {
  const tones: Record<string, string> = {
    slate: 'bg-slate-100 text-slate-700 border-slate-200',
    green: 'bg-emerald-50 text-emerald-800 border-emerald-200',
    red: 'bg-rose-50 text-rose-800 border-rose-200',
    amber: 'bg-amber-50 text-amber-800 border-amber-200',
    blue: 'bg-brand-50 text-brand-700 border-brand-200',
    violet: 'bg-violet-50 text-violet-800 border-violet-200',
  };
  return (
    <span
      title={title}
      className={clsx(
        'inline-flex items-center gap-1 rounded border px-1.5 py-0.5 text-[11px] font-medium whitespace-nowrap',
        tones[tone],
      )}
    >
      {children}
    </span>
  );
}

export function StatusBadge({ status }: { status: string }) {
  const map: Record<string, { tone: 'slate' | 'green' | 'red' | 'amber' | 'blue'; label: string }> = {
    ready: { tone: 'green', label: 'готов' },
    uploaded: { tone: 'slate', label: 'загружен' },
    needs_mapping: { tone: 'amber', label: 'нужно сопоставление' },
    queued: { tone: 'blue', label: 'в очереди' },
    validating: { tone: 'blue', label: 'проверка' },
    importing: { tone: 'blue', label: 'импорт' },
    calculating: { tone: 'blue', label: 'расчёт' },
    failed: { tone: 'red', label: 'ошибка' },
    cancelled: { tone: 'slate', label: 'отменён' },
  };
  const s = map[status] ?? { tone: 'slate' as const, label: status };
  return <Badge tone={s.tone}>{s.label}</Badge>;
}

export function EmptyState({ title, hint, action }: { title: string; hint?: string; action?: ReactNode }) {
  return (
    <div className="flex flex-col items-center justify-center gap-2 rounded-lg border border-dashed border-slate-300 bg-white p-10 text-center">
      <p className="text-sm font-medium text-slate-700">{title}</p>
      {hint && <p className="max-w-md text-xs text-slate-500">{hint}</p>}
      {action}
    </div>
  );
}

export function Modal({
  open,
  onClose,
  title,
  children,
  wide,
}: {
  open: boolean;
  onClose: () => void;
  title: string;
  children: ReactNode;
  wide?: boolean;
}) {
  if (!open) return null;

  // Портал в body: предки с backdrop-filter/sticky (шапка страницы) создают
  // containing block для fixed-элементов, и попап иначе растягивался бы от
  // границ шапки, а не окна — оказываясь «под» контентом страницы.
  return createPortal(
    <div
      className="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-slate-900/40 p-8"
      onMouseDown={(e) => e.target === e.currentTarget && onClose()}
    >
      <div className={clsx('card w-full p-5', wide ? 'max-w-4xl' : 'max-w-lg')}>
        <div className="mb-4 flex items-center justify-between">
          <h2 className="text-base font-semibold">{title}</h2>
          <button className="btn-ghost -mr-2 px-2" onClick={onClose} aria-label="Закрыть">
            ✕
          </button>
        </div>
        {children}
      </div>
    </div>,
    document.body,
  );
}

export function StatCard({
  label,
  value,
  hint,
  children,
}: {
  label: string;
  value: ReactNode;
  hint?: string;
  children?: ReactNode;
}) {
  return (
    <div className="card flex flex-col gap-1 p-4">
      <span className="text-[11px] font-medium uppercase tracking-wider text-slate-500">{label}</span>
      <span className="text-xl font-semibold text-slate-900">{value}</span>
      {hint && <span className="text-[11px] leading-snug text-slate-500">{hint}</span>}
      {children}
    </div>
  );
}

export function Tooltip({ children }: { children: ReactNode }) {
  return (
    <span className="cursor-help border-b border-dotted border-slate-400 text-slate-500" tabIndex={0}>
      {children}
    </span>
  );
}

export function ErrorNote({ message }: { message: string }) {
  return (
    <div className="rounded border border-rose-200 bg-rose-50 px-3 py-2 text-sm text-rose-800" role="alert">
      {message}
    </div>
  );
}
