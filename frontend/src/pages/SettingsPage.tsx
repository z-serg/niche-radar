import { PageHeader } from '../components/Layout';
import { SETTINGS_HELP } from '../lib/help';

/** Сведения о системе и подключении внешнего ИИ-агента (MCP). */
export function SettingsPage() {
  return (
    <>
      <PageHeader title="Настройки" subtitle="Сведения о системе" help={SETTINGS_HELP} />
      <div className="p-4">
        <section className="card max-w-2xl space-y-2 p-4 text-sm">
          <h2 className="text-sm font-semibold">MCP для внешнего ИИ-агента</h2>
          <p className="text-xs text-slate-500">
            Read-only сервер niche-radar (stdio). Конфигурация клиента — файл{' '}
            <code className="rounded bg-slate-100 px-1 font-mono text-[11px]">docs/mcp-client.example.json</code>:
          </p>
          <pre className="overflow-x-auto rounded bg-slate-900 p-3 text-[11px] leading-relaxed text-slate-100">
{`{
  "mcpServers": {
    "niche-radar": {
      "command": "docker",
      "args": [
        "compose", "exec", "-T", "app",
        "php", "artisan", "mcp:start", "niche-radar"
      ],
      "cwd": "/path/to/Bukvariks"
    }
  }
}`}
          </pre>
          <p className="text-xs text-slate-500">
            Транспорт stdio рассчитан на локального агента на Docker-хосте с доверием владельца.
            Удалённому облачному клиенту нужен HTTP/OAuth и публичный адрес — это вне версии 1.0.
          </p>
        </section>
      </div>
    </>
  );
}
