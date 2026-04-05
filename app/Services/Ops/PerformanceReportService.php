<?php

namespace App\Services\Ops;

use App\Models\PerformanceRun;
use Illuminate\Support\Facades\Storage;

class PerformanceReportService
{
    /**
     * @return array{path:string,absolute_path:string,filename:string,content:string}
     */
    public function generate(PerformanceRun $run): array
    {
        $run->loadMissing(['shop', 'feedProfile', 'user', 'stageRuns']);
        $disk = Storage::disk(config('feed_mediator.storage_disk'));
        $relativePath = trim(config('feed_mediator.runbooks_directory'), '/').'/performance/performance-run-'.$run->id.'.json';
        $absolutePath = $disk->path($relativePath);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0777, true);
        }

        $content = json_encode([
            'run' => $run->toArray(),
            'stages' => $run->stageRuns->map(fn ($stage) => $stage->toArray())->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

        $disk->put($relativePath, $content);

        return [
            'path' => $relativePath,
            'absolute_path' => $absolutePath,
            'filename' => 'performance-run-'.$run->id.'.json',
            'content' => $content,
        ];
    }
}
