<?php

namespace App\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\DB;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Name;
use Laravel\Mcp\Server\Tool;

#[Name('get_dataset_schema')]
#[Description('Замеры отчёта, их даты (если известны), определения полей и предупреждения качества.')]
class GetDatasetSchemaTool extends Tool
{
    use McpToolSupport;

    public function handle(Request $request): Response
    {
        $datasetId = (string) $request->get('dataset_id');
        $d = DB::table('datasets')->where('id', $datasetId)->first();
        if ($d === null) {
            return Response::error('Отчёт не найден: '.$datasetId);
        }

        $scans = DB::table('dataset_scans')->where('dataset_id', $datasetId)->orderBy('ordinal')->get();
        $run = DB::table('metric_runs')->where('dataset_id', $datasetId)->where('is_default', true)
            ->where('status', 'ready')->orderByDesc('created_at')->first();

        return $this->jsonResponse([
            'dataset' => [
                'dataset_id' => $d->id,
                'title' => $d->title,
                'status' => $d->status,
                'coverage_type' => $d->coverage_type,
                'region' => $d->region,
                'frequency_definition' => $d->frequency_definition,
                'period_description' => $d->period_description,
                'quality_flags' => json_decode((string) $d->quality_flags, true) ?: [],
                'phrase_count' => $d->row_count !== null ? (int) $d->row_count : null,
            ],
            'scans' => $scans->map(fn ($s) => [
                'ordinal' => (int) $s->ordinal, // 1 = самый старый
                'source_header' => $s->source_header,
                'source_suffix' => $s->source_suffix,
                'scan_date' => $s->scan_date,
                'date_known' => $s->scan_date !== null,
            ]),
            'field_definitions' => [
                'exact' => 'точная частотность "[!...]" — целые числа показов; NULL = пропуск',
                'broad' => 'широкая частотность; не суммируется как рынок',
                'rank_current' => 'позиция в текущем рейтинге; не ID фразы',
                'rank_previous' => '0 = не было в прошлом рейтинге',
            ],
            'metric_run' => $run ? [
                'metric_run_id' => $run->id,
                'algorithm_version' => $run->algorithm_version,
                'rules_version' => $run->rules_version,
                'range' => [(int) $run->ordinal_from, (int) $run->ordinal_to],
                'summary' => json_decode((string) $run->results_summary, true),
            ] : null,
            'warning' => $d->status !== 'ready' ? 'отчёт не опубликован: поиск недоступен' : null,
        ]);
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'dataset_id' => $schema->string()->description('UUID отчёта из list_datasets')->required(),
        ];
    }
}
