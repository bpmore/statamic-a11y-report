<?php

declare(strict_types=1);

namespace Bpmore\A11yReport\Storage;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/** Private report bytes must survive the container that generated them. */
final class ReportFiles
{
    public function __construct(private readonly Application $app) {}

    public function put(string $relative, string $contents): string
    {
        $this->validate($relative);
        if ($disk = $this->disk()) {
            // R2 removes HTML objects. A private JSON envelope preserves the
            // exact bytes without publishing executable report markup.
            $payload = json_encode([
                'sha256' => hash('sha256', $contents),
                'bytes' => strlen($contents),
                'base64' => base64_encode($contents),
            ], JSON_THROW_ON_ERROR);
            if (! Storage::disk($disk)->put($relative.'.json', $payload)) {
                throw new RuntimeException('The private report file could not be saved.');
            }
        }
        $this->cache($relative, $contents);

        return $relative;
    }

    public function absolutePath(?string $relative): ?string
    {
        if ($relative === null) {
            return null;
        }
        $this->validate($relative);
        if ($disk = $this->disk()) {
            $payload = Storage::disk($disk)->get($relative.'.json');
            if ($payload === null) {
                return null;
            }
            $data = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            $contents = isset($data['base64']) && is_string($data['base64']) ? base64_decode($data['base64'], true) : false;
            if ($contents === false || ! isset($data['sha256'], $data['bytes'])
                || strlen($contents) !== $data['bytes'] || ! is_string($data['sha256'])
                || ! hash_equals($data['sha256'], hash('sha256', $contents))) {
                throw new RuntimeException('The private report file failed its integrity check.');
            }
            $this->cache($relative, $contents);
        }

        return $this->app->storagePath($relative);
    }

    private function disk(): ?string
    {
        $disk = config('statamic-a11y-report.files.disk');

        return is_string($disk) && $disk !== '' ? $disk : null;
    }

    private function validate(string $relative): void
    {
        if (! preg_match('~\Aa11y-report/reports/[a-f0-9-]{36}\.(html|json|pdf)\z~D', $relative)) {
            throw new RuntimeException('Invalid private report path.');
        }
    }

    private function cache(string $relative, string $contents): void
    {
        $path = $this->app->storagePath($relative);
        if (! is_dir(dirname($path)) && ! mkdir(dirname($path), 0700, true) && ! is_dir(dirname($path))) {
            throw new RuntimeException('The private report cache could not be created.');
        }
        $temporary = tempnam(dirname($path), '.report-');
        if ($temporary === false) {
            throw new RuntimeException('The private report cache could not be created.');
        }
        try {
            if (file_put_contents($temporary, $contents) !== strlen($contents) || ! chmod($temporary, 0600) || ! rename($temporary, $path)) {
                throw new RuntimeException('The private report cache could not be saved.');
            }
        } finally {
            if (is_file($temporary)) {
                unlink($temporary);
            }
        }
    }
}
