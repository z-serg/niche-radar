import { ReactNode, useEffect, useState } from 'react';
import { NavLink } from 'react-router-dom';
import clsx from 'clsx';
import { HelpIcon } from './HelpIcon';
import { Icon, IconName } from './icons';

const NAV: { to: string; label: string; icon: IconName }[] = [
  { to: '/dashboard', label: 'Дашборд', icon: 'dashboard' },
  { to: '/data', label: 'Данные', icon: 'database' },
  { to: '/explore', label: 'Исследование', icon: 'search' },
  { to: '/candidates', label: 'Кандидаты', icon: 'list-filter' },
  { to: '/niches', label: 'Ниши', icon: 'layers' },
  { to: '/hypotheses', label: 'Гипотезы', icon: 'lightbulb' },
  { to: '/settings', label: 'Настройки', icon: 'settings' },
];

/** Ключ localStorage для сохранения свёрнутого состояния сайдбара между сессиями. */
const SIDEBAR_KEY = 'niche-radar:sidebar-collapsed';

export function Layout({ children }: { children: ReactNode }) {
  const [collapsed, setCollapsed] = useState(() => {
    try {
      return localStorage.getItem(SIDEBAR_KEY) === '1';
    } catch {
      return false;
    }
  });

  useEffect(() => {
    try {
      localStorage.setItem(SIDEBAR_KEY, collapsed ? '1' : '0');
    } catch {
      /* localStorage недоступен (приватный режим) — просто не сохраняем */
    }
  }, [collapsed]);

  return (
    <div className="flex min-h-screen">
      <aside
        className={clsx(
          'flex shrink-0 flex-col border-r border-slate-200 bg-white transition-[width] duration-200',
          collapsed ? 'w-16' : 'w-52',
        )}
      >
        <div className="border-b border-slate-200 px-4 py-4">
          <div className={clsx('flex items-center gap-2', collapsed && 'justify-center')}>
            <span className="flex h-8 w-8 items-center justify-center rounded-md bg-brand-600 text-sm font-bold text-white">
              NR
            </span>
            {!collapsed && (
              <div>
                <p className="text-sm font-semibold leading-tight">Niche Radar</p>
                <p className="text-[11px] text-slate-500">исследование спроса</p>
              </div>
            )}
          </div>
        </div>
        <nav className="flex flex-col gap-0.5 p-2" aria-label="Разделы">
          {NAV.map((item) => (
            <NavLink
              key={item.to}
              to={item.to}
              title={collapsed ? item.label : undefined}
              className={({ isActive }) =>
                clsx(
                  'flex items-center rounded-md px-3 py-2 text-sm font-medium text-slate-700 transition-colors hover:bg-slate-100',
                  collapsed ? 'justify-center' : 'gap-2.5',
                  isActive && 'bg-brand-50 text-brand-700',
                )
              }
            >
              <Icon name={item.icon} />
              {!collapsed && item.label}
            </NavLink>
          ))}
        </nav>
        <div className="mt-auto space-y-0.5 border-t border-slate-200 p-2">
          <button
            className={clsx('btn-ghost w-full text-sm', !collapsed && 'justify-start')}
            onClick={() => setCollapsed((v) => !v)}
            title={collapsed ? 'Развернуть меню' : 'Свернуть меню'}
            aria-expanded={!collapsed}
            aria-label={collapsed ? 'Развернуть меню' : 'Свернуть меню'}
          >
            <Icon name={collapsed ? 'chevron-right' : 'chevron-left'} />
            {!collapsed && 'Свернуть'}
          </button>
          {!collapsed && (
            <p className="mt-2 px-3 text-[10px] leading-snug text-slate-400">
              Метрики: приоритет исследования, не вероятность успеха. Данные о конкуренции отсутствуют.
            </p>
          )}
        </div>
      </aside>
      <main className="min-w-0 flex-1 overflow-x-hidden">{children}</main>
    </div>
  );
}

export function PageHeader({
  title,
  subtitle,
  actions,
  help,
}: {
  title: string;
  subtitle?: ReactNode;
  actions?: ReactNode;
  /** Содержимое попапа справки по странице (иконка «?» рядом с заголовком). */
  help?: ReactNode;
}) {
  return (
    <header className="sticky top-0 z-20 flex items-center justify-between gap-4 border-b border-slate-200 bg-slate-50/95 px-6 py-3 backdrop-blur">
      <div className="min-w-0">
        {/* Иконка справки — в строке самого заголовка H1 (справа от него). */}
        <div className="flex items-center gap-2">
          <h1 className="truncate text-lg font-semibold">{title}</h1>
          {help && <HelpIcon title={title}>{help}</HelpIcon>}
        </div>
        {subtitle && <p className="mt-0.5 text-xs text-slate-500">{subtitle}</p>}
      </div>
      <div className="flex shrink-0 items-center gap-2">{actions}</div>
    </header>
  );
}
