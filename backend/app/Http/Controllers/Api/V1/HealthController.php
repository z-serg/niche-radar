<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

/**
 * Диагностические статусы (ТЗ §12.3): доступность процесса отличается
 * от готовности БД; состояние worker отражается heartbeat.
 */
class HealthController extends Controller
{
    public function ping(): JsonResponse
    {
        return response()->json(['status' => 'ok', 'service' => 'niche-radar', 'time' => now()->toDateTimeString()]);
    }

    public function health(): JsonResponse
    {
        $db = false;
        $dbError = null;
        try {
            $db = DB::selectOne('SELECT 1 AS ok') !== null;
        } catch (\Throwable $e) {
            $dbError = $e->getMessage();
        }

        $heartbeats = DB::table('import_jobs')
            ->whereIn('status', ['running', 'queued'])
            ->max('heartbeat_at');

        return response()->json([
            'status' => $db ? 'healthy' : 'degraded',
            'checks' => [
                'process' => ['ok' => true],
                'database' => ['ok' => $db, 'error' => $dbError],
                'workers' => [
                    'ok' => true,
                    'last_import_heartbeat' => $heartbeats,
                ],
            ],
        ], $db ? 200 : 503);
    }
}
