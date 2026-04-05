<?php

namespace App\Console\Commands;

use App\Models\FeedProfile;
use App\Services\Mappings\Automation\MappingTemplateLibraryService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class MappingTemplateExportCommand extends Command
{
    protected $signature = 'mapping:template:export {feedProfileId}';

    protected $description = 'Export a feed profile mapping template to storage.';

    public function handle(MappingTemplateLibraryService $service): int
    {
        $feedProfile = FeedProfile::query()->findOrFail((int) $this->argument('feedProfileId'));
        $payload = json_encode($service->exportPayload($feedProfile), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $directory = storage_path('app/mapping-templates');

        if (! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        $path = $directory.DIRECTORY_SEPARATOR.sprintf('feed-profile-%d-template.json', $feedProfile->id);
        File::put($path, $payload);

        $this->info('Template exported to '.$path);

        return self::SUCCESS;
    }
}
