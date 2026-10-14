<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Storage;

use ElSchneider\GreenwashingChecker\Analysis\Category;
use ElSchneider\GreenwashingChecker\Analysis\ClaimAnalyzer;
use ElSchneider\GreenwashingChecker\Analysis\Finding;
use ElSchneider\GreenwashingChecker\Analysis\Severity;
use ElSchneider\GreenwashingChecker\Settings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonException;
use LogicException;
use RuntimeException;
use UnexpectedValueException;

final class UnitCache
{
    private const PREFIX = 'greenwashing-checker:unit:';

    public function __construct(private ?string $directory = null)
    {
        $this->directory ??= storage_path('statamic/greenwashing-checker/units');
    }

    /**
     * Fingerprint of the text and every analysis-changing input: model, prompt version,
     * analysis rules and the context budget. Entry ID, field path, context text and locales
     * are excluded, so identical text shares one unit file across entries and users; the
     * current field path is rebound when findings are served.
     */
    public function hash(string $text, ?array $rules = null): string
    {
        return hash('sha256', json_encode([
            $text, config('statamic.greenwashing-checker.model'), ClaimAnalyzer::PROMPT_VERSION,
            $rules ?? Settings::analysis(), max(0, (int) config('statamic.greenwashing-checker.max_context_chars')),
        ], JSON_THROW_ON_ERROR));
    }

    public function get(string $hash): ?array
    {
        $path = $this->path($hash);
        // Absence means this text/analysis fingerprint has not been checked (or was pruned).
        if (! File::exists($path)) {
            return null;
        }
        try {
            $record = json_decode(File::get($path), true, flags: JSON_THROW_ON_ERROR);
            $this->validate($record);

            return $record;
        } catch (JsonException|UnexpectedValueException $exception) {
            Log::error('greenwashing-checker: unit file could not be read', ['file' => basename($path), 'exception' => $exception]);
            throw new UnitFileException($path, $exception);
        }
    }

    /** @param list<Finding> $findings */
    public function put(string $hash, array $findings): void
    {
        Cache::lock(self::PREFIX.$hash.':lock', 10)->block(5, function () use ($hash, $findings): void {
            $this->store($hash, [
                'findings' => array_map(fn (Finding $finding) => $finding->jsonSerialize(), $findings),
                'dismissals' => $this->get($hash)['dismissals'] ?? [],
            ]);
        });
    }

    public function setDismissed(string $hash, string $findingId, string $userId, bool $dismissed): bool
    {
        return Cache::lock(self::PREFIX.$hash.':lock', 10)->block(5, function () use ($hash, $findingId, $userId, $dismissed): bool {
            $record = $this->get($hash);
            if ($record === null || ! in_array($findingId, array_column($record['findings'], 'id'), true)) {
                return false;
            }
            if ($dismissed) {
                $record['dismissals'][$findingId] = ['user' => $userId, 'at' => now()->toIso8601String()];
            } else {
                unset($record['dismissals'][$findingId]);
            }
            $this->store($hash, $record);

            return true;
        });
    }

    /** @param array<string, string> $hashes */
    public function result(array $hashes, array $snapshot = []): array
    {
        $units = [];
        foreach ($hashes as $path => $hash) {
            $record = $this->get($hash) ?? $snapshot[$hash] ?? throw new LogicException('Missing analyzed unit.');
            $approvals = app(ApprovalStore::class)->all($hash);
            $findings = array_map(function (array $finding) use ($path, $record, $approvals): array {
                $finding['path'] = $path;
                $finding['dismissed'] = isset($record['dismissals'][$finding['id']]);
                $finding['approval'] = $approvals[$finding['id']] ?? null;

                return $finding;
            }, array_values(array_filter($record['findings'], fn (array $finding) => Settings::get('threshold') !== 'high' || $finding['severity'] === 'high')));
            $units[$path] = ['hash' => $hash, 'findings' => $findings];
        }

        return ['units' => $units, 'analyzed_at' => now()->toIso8601String()];
    }

    /** @param list<string> $referencedHashes */
    public function prune(array $referencedHashes, bool $dryRun = false): int
    {
        $referenced = array_fill_keys($referencedHashes, true);
        $count = 0;
        foreach (File::glob($this->directory.'/*.json') as $path) {
            $hash = basename($path, '.json');
            // Files the store did not write are not ours to delete.
            if (! preg_match('/^[a-f0-9]{64}$/D', $hash) || isset($referenced[$hash])) {
                continue;
            }
            $count += Cache::lock(self::PREFIX.$hash.':lock', 10)->block(5, function () use ($path, $dryRun): int {
                if ($dryRun) {
                    return (int) File::exists($path);
                }
                if (File::delete($path)) {
                    return 1;
                }
                // Another prune may have removed the file while we waited for its lock.
                if (! File::exists($path)) {
                    return 0;
                }
                throw new RuntimeException('Cannot prune analysis file '.basename($path).'.');
            });
        }

        return $count;
    }

    private function path(string $hash): string
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $hash)) {
            throw new InvalidArgumentException('Invalid greenwashing unit hash.');
        }

        return $this->directory.'/'.$hash.'.json';
    }

    private function store(string $hash, array $record): void
    {
        $path = $this->path($hash);
        File::ensureDirectoryExists($this->directory);
        File::replace($path, json_encode($record, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)."\n");
    }

    private function validate(mixed $record): void
    {
        if (! is_array($record) || ! is_array($record['findings'] ?? null) || ! array_is_list($record['findings']) || ! is_array($record['dismissals'] ?? null)) {
            throw new UnexpectedValueException('Invalid unit record.');
        }
        foreach ($record['findings'] as $finding) {
            if (! is_array($finding)) {
                throw new UnexpectedValueException('Invalid unit finding.');
            }
            foreach (['id', 'path', 'quote', 'category', 'severity', 'explanation'] as $key) {
                if (! is_string($finding[$key] ?? null)) {
                    throw new UnexpectedValueException('Invalid unit finding field: '.$key);
                }
            }
            if (Category::tryFrom($finding['category']) === null || Severity::tryFrom($finding['severity']) === null) {
                throw new UnexpectedValueException('Invalid unit finding category or severity.');
            }
            if (! is_bool($finding['anchored'] ?? null) || ! is_bool($finding['dismissed'] ?? null) || ! is_array($finding['suggestions'] ?? null) || ! array_is_list($finding['suggestions'])) {
                throw new UnexpectedValueException('Invalid unit finding fields.');
            }
            foreach ($finding['suggestions'] as $suggestion) {
                if (! is_string($suggestion)) {
                    throw new UnexpectedValueException('Invalid unit suggestion.');
                }
            }
        }
        foreach ($record['dismissals'] as $dismissal) {
            if (! is_array($dismissal) || ! is_string($dismissal['user'] ?? null) || ! is_string($dismissal['at'] ?? null)) {
                throw new UnexpectedValueException('Invalid unit dismissal.');
            }
        }
    }
}
