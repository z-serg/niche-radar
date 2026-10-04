import { ReactNode, useEffect, useState } from 'react';
import { Modal } from './ui';

/**
 * Иконка «?» рядом с заголовком страницы: открывает попап с подробной
 * инструкцией по работе со страницей. Доступна с клавиатуры, закрывается
 * по Esc и кликом вне попапа.
 */
export function HelpIcon({ title, children }: { title: string; children: ReactNode }) {
  const [open, setOpen] = useState(false);

  useEffect(() => {
    if (!open) return;
    const onKey = (e: KeyboardEvent) => {
      if (e.key === 'Escape') setOpen(false);
    };
    window.addEventListener('keydown', onKey);
    return () => window.removeEventListener('keydown', onKey);
  }, [open]);

  return (
    <>
      <button
        type="button"
        onClick={() => setOpen(true)}
        aria-label={`Справка: ${title}`}
        title="Справка по странице"
        className="flex h-6 w-6 shrink-0 items-center justify-center rounded-full border border-slate-300 text-sm font-semibold text-slate-500 transition-colors hover:border-brand-400 hover:bg-brand-50 hover:text-brand-600 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand-500"
      >
        ?
      </button>
      <Modal open={open} onClose={() => setOpen(false)} title={`Справка: ${title}`} wide>
        <div className="max-h-[70vh] space-y-4 overflow-y-auto pr-1 text-sm leading-relaxed text-slate-700">
          {children}
        </div>
      </Modal>
    </>
  );
}

/** Секция справки с подзаголовком. */
export function HelpSection({ heading, children }: { heading: string; children: ReactNode }) {
  return (
    <section>
      <h3 className="mb-1.5 text-sm font-semibold text-slate-900">{heading}</h3>
      <div className="space-y-1.5 text-[13px] leading-relaxed">{children}</div>
    </section>
  );
}
