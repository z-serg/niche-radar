import {
  CartesianGrid, Legend, Line, LineChart, ResponsiveContainer, Tooltip as RTooltip,
  XAxis, YAxis,
} from 'recharts';
import type { HistoryPoint } from '../api/types';

/** Мини-история в ячейке таблицы (только точная частотность). */
export function Sparkline({ history, width = 88, height = 24 }: { history: HistoryPoint[]; width?: number; height?: number }) {
  if (history.length < 2) return <span className="text-xs text-slate-400">нет истории</span>;
  const data = history.map((h) => ({ o: h.ordinal, v: h.exact }));
  const max = Math.max(...data.map((d) => d.v ?? 0));
  const min = Math.min(...data.map((d) => d.v ?? 0));
  const points = data
    .map((d, i) => {
      if (d.v === null || d.v === undefined) return null;
      const x = (i / (data.length - 1)) * (width - 2) + 1;
      const range = max - min || 1;
      const y = height - 2 - ((d.v - min) / range) * (height - 4);
      return `${x.toFixed(1)},${y.toFixed(1)}`;
    })
    .filter(Boolean)
    .join(' ');
  const rising = (data[data.length - 1]?.v ?? 0) >= (data[0]?.v ?? 0);

  return (
    <svg width={width} height={height} className="align-middle" aria-hidden>
      <polyline
        points={points}
        fill="none"
        strokeWidth="1.5"
        className={rising ? 'stroke-emerald-600' : 'stroke-rose-500'}
      />
    </svg>
  );
}

/**
 * График истории фразы: раздельные серии точной и широкой частотности,
 * явная шкала; пропуски не соединяются (connectNulls=false, ТЗ §5.3).
 */
export function HistoryChart({ history, height = 300 }: { history: HistoryPoint[]; height?: number }) {
  const data = history.map((h) => ({
    ordinal: `замер ${h.ordinal}`,
    'точная частотность': h.exact,
    'широкая частотность': h.broad,
  }));

  return (
    <div style={{ height }} className="w-full">
      <ResponsiveContainer>
        <LineChart data={data} margin={{ top: 8, right: 16, bottom: 4, left: 8 }}>
          <CartesianGrid strokeDasharray="3 3" stroke="#e2e8f0" />
          <XAxis dataKey="ordinal" tick={{ fontSize: 11 }} />
          <YAxis
            tick={{ fontSize: 11 }}
            tickFormatter={(v: number) => new Intl.NumberFormat('ru-RU', { notation: 'compact' }).format(v)}
            width={64}
          />
          <RTooltip
            formatter={(v: unknown) => (v === null || v === undefined ? 'нет значения' : new Intl.NumberFormat('ru-RU').format(Number(v)))}
          />
          <Legend wrapperStyle={{ fontSize: 12 }} />
          {/* Порядковая ось: замеры не привязаны к календарю (ТЗ §4.1). */}
          <Line
            type="linear"
            dataKey="точная частотность"
            stroke="#1f3fd1"
            strokeWidth={2}
            dot={{ r: 3 }}
            connectNulls={false}
          />
          <Line
            type="linear"
            dataKey="широкая частотность"
            stroke="#94a3b8"
            strokeWidth={1.5}
            strokeDasharray="5 4"
            dot={{ r: 2 }}
            connectNulls={false}
          />
        </LineChart>
      </ResponsiveContainer>
    </div>
  );
}
