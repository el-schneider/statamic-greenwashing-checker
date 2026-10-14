<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Storage;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use JsonException;

/**
 * Documented approvals as permanent editorial JSON records under
 * `content/greenwashing-checker/approvals/<unit_hash>.json`, keyed by finding ID. They
 * survive cache clearing and pruning; changed text or analysis rules select a different unit
 * hash, so old approvals stop applying until the exact fingerprint returns. Corrupt files
 * throw an exception naming the file.
 */
final class ApprovalStore
{
    private string $directory;

    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? base_path('content/greenwashing-checker/approvals');
    }

    public function all(string $hash): array
    {
        $path = $this->path($hash);

        // No file means this exact text/analysis fingerprint has no documented decisions.
        if (! File::exists($path)) {
            return [];
        }
        try {
            $records = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new ApprovalFileException($path, $exception);
        }
        if (! is_array($records)) {
            throw new ApprovalFileException($path);
        }

        return $records;
    }

    public function set(string $hash, string $findingId, ?array $approval): void
    {
        Cache::lock('greenwashing-checker:approval:'.$hash, 10)->block(5, function () use ($hash, $findingId, $approval): void {
            $records = $this->all($hash);
            if ($approval === null) {
                unset($records[$findingId]);
            } else {
                $records[$findingId] = $approval;
            }
            $path = $this->path($hash);
            if ($records === []) {
                File::delete($path);

                return;
            }
            File::ensureDirectoryExists($this->directory);
            // Atomic replacement plus a shared lock preserves concurrent decisions on this text.
            File::replace($path, json_encode($records, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
        });
    }

    private function path(string $hash): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new InvalidArgumentException('Invalid greenwashing approval hash.');
        }

        return $this->directory.'/'.$hash.'.json';
    }
}
