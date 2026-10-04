// Форматирование чисел и подписей. Frontend не пересчитывает
// бизнес-метрики — только отображает значения backend (ТЗ §7).

export function fmtInt(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  return new Intl.NumberFormat('ru-RU').format(v);
}

export function fmtCompact(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  return new Intl.NumberFormat('ru-RU', { notation: 'compact', maximumFractionDigits: 1 }).format(v);
}

/** Доля -> проценты: 0.32 -> «+32 %» / «−12 %». */
export function fmtPct(v: number | null | undefined, withSign = true): string {
  if (v === null || v === undefined) return '—';
  const pct = v * 100;
  const sign = withSign && pct > 0 ? '+' : '';
  return `${sign}${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(pct)} %`;
}

export function fmtNum(v: number | null | undefined, digits = 2): string {
  if (v === null || v === undefined) return '—';
  return new Intl.NumberFormat('ru-RU', { maximumFractionDigits: digits }).format(v);
}

export function fmtBytes(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  const units = ['Б', 'КБ', 'МБ', 'ГБ', 'ТБ'];
  let val = v;
  let i = 0;
  while (val >= 1024 && i < units.length - 1) {
    val /= 1024;
    i++;
  }
  return `${new Intl.NumberFormat('ru-RU', { maximumFractionDigits: 1 }).format(val)} ${units[i]}`;
}

export function fmtSeconds(v: number | null | undefined): string {
  if (v === null || v === undefined) return '—';
  if (v < 90) return `${Math.round(v)} с`;
  const m = Math.floor(v / 60);
  const h = Math.floor(m / 60);
  if (h > 0) return `~${h} ч ${m % 60} мин`;
  return `~${m} мин`;
}

/** Прирост с направлением: и цвет, и знак, и подпись (ТЗ §5). */
export function delta(v: number | null | undefined): { text: string; cls: string } {
  if (v === null || v === undefined) return { text: 'н/д', cls: 'text-slate-400' };
  if (v > 0) return { text: `▲ ${fmtInt(v)}`, cls: 'text-emerald-700' };
  if (v < 0) return { text: `▼ ${fmtInt(Math.abs(v))}`, cls: 'text-rose-700' };
  return { text: '= 0', cls: 'text-slate-500' };
}

export const COVERAGE_LABELS: Record<string, string> = {
  unknown: 'охват неизвестен',
  full_top: 'полный топ',
  sample: 'выборка',
  filtered: 'отфильтрованная выборка',
};

export const STATUS_LABELS: Record<string, string> = {
  uploaded: 'загружен',
  needs_mapping: 'требуется сопоставление',
  queued: 'в очереди',
  validating: 'проверка',
  importing: 'импорт',
  calculating: 'расчёт метрик',
  ready: 'готов',
  failed: 'ошибка',
  cancelled: 'отменён',
};

export const PRESET_LABELS: Record<string, string> = {
  sustained_growth: 'Устойчивый рост',
  early_signals: 'Ранние сигналы',
  zero_baseline: 'Рост от нуля',
  growth_leaders: 'Лидеры роста',
  fall_leaders: 'Лидеры падения',
};

export const TASK_CATEGORY_LABELS: Record<string, string> = {
  conversion: 'конвертация',
  calculation: 'расчёт',
  generation: 'генерация',
  file_processing: 'обработка файлов',
  monitoring: 'мониторинг',
  comparison: 'сравнение',
  accounting: 'учёт',
  automation: 'автоматизация',
  parsing: 'извлечение данных',
  weak: 'слабый сигнал',
};

export const FLAG_LABELS: Record<string, string> = {
  is_new: 'впервые в рейтинге',
  low_base: 'низкая база',
  zero_baseline: 'рост от нуля',
  incomplete_history: 'неполная история',
};

export const NULL_REASON_LABELS: Record<string, string> = {
  zero_baseline: 'нулевая база',
  missing_boundary: 'нет граничных значений',
  insufficient_history: 'мало замеров',
  incomplete_series: 'пропуски в ряду',
  low_base: 'низкая база',
};
