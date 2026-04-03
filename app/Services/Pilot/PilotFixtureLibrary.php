<?php

namespace App\Services\Pilot;

use RuntimeException;

class PilotFixtureLibrary
{
    public function basePath(): string
    {
        return (string) config('feed_mediator.pilot.fixtures_path', base_path('database/samples/pilot'));
    }

    public function manifest(): array
    {
        return $this->json('manifest.json');
    }

    public function promYmlPath(): string
    {
        return $this->path('sources/prom_yml/pilot-feed.yml');
    }

    public function promYmlContent(): string
    {
        return $this->text('sources/prom_yml/pilot-feed.yml');
    }

    public function promApiResponse(string $name): array
    {
        return $this->json('sources/prom_api/'.$name.'.json');
    }

    public function dictionarySample(string $type, string $format = 'json'): string
    {
        return $this->text(sprintf('kasta-dictionaries/%s.%s', $type, $format));
    }

    public function feedbackContent(string $format): string
    {
        return $this->text('feedback/feedback.'.$format);
    }

    public function feedbackFilename(string $format): string
    {
        return 'pilot-feedback.'.$format;
    }

    public function expectedJson(string $name): array
    {
        return $this->json('expected/'.$name.'.json');
    }

    public function expectedText(string $name, string $extension = 'xml'): string
    {
        return $this->text(sprintf('expected/%s.%s', $name, $extension));
    }

    public function path(string $relativePath): string
    {
        $path = rtrim($this->basePath(), DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);

        if (! is_file($path)) {
            throw new RuntimeException(sprintf('Pilot fixture [%s] does not exist.', $relativePath));
        }

        return $path;
    }

    public function text(string $relativePath): string
    {
        $content = file_get_contents($this->path($relativePath));

        if ($content === false) {
            throw new RuntimeException(sprintf('Unable to read pilot fixture [%s].', $relativePath));
        }

        return $content;
    }

    public function json(string $relativePath): array
    {
        $decoded = json_decode($this->text($relativePath), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('Pilot fixture [%s] must decode to an array.', $relativePath));
        }

        return $decoded;
    }
}
