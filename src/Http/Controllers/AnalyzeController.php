<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Http\Controllers;

use ElSchneider\GreenwashingChecker\Ai\AiErrorCode;
use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\AiModel;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use ElSchneider\GreenwashingChecker\Content\ContentWalker;
use ElSchneider\GreenwashingChecker\Http\EntryAccess;
use ElSchneider\GreenwashingChecker\Jobs\AnalyzeEntryJob;
use ElSchneider\GreenwashingChecker\Scanning\EntryScanner;
use ElSchneider\GreenwashingChecker\Settings;
use ElSchneider\GreenwashingChecker\Storage\ApprovalFileException;
use ElSchneider\GreenwashingChecker\Storage\EntryIndex;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use ElSchneider\GreenwashingChecker\Storage\UnitFileException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use JsonException;
use Statamic\Fields\Field;
use UnexpectedValueException;

final class AnalyzeController
{
    public function __invoke(Request $request, ContentWalker $walker, UnitCache $cache, TrackedJobs $jobs): JsonResponse
    {
        $data = $request->validate([
            'reference' => ['nullable', 'string', 'regex:/^entry::[^:]+$/'],
            'collection' => ['required', 'string'], 'blueprint' => ['required', 'string'],
            'site' => ['required', 'string'], 'values' => ['present', 'array'],
        ]);
        [$collection, $site, $entry, $user] = EntryAccess::authorize($request);
        if ($entry !== null) {
            $blueprint = $entry->blueprint();
            abort_unless($blueprint->handle() === $data['blueprint'], 422);
        } else {
            $blueprint = $collection->entryBlueprint($data['blueprint']);
            abort_if($blueprint === null, 404);
        }
        try {
            $model = AiModel::fromString(config('statamic.greenwashing-checker.model'));
            if (! $model->isConfigured()) {
                throw new AiException(AiErrorCode::Configuration);
            }
        } catch (AiException $exception) {
            Log::error('greenwashing-checker: configuration failed', [
                'exception' => $exception, 'code' => $exception->errorCode->value,
                'model' => config('statamic.greenwashing-checker.model'),
                'entry' => $data['reference'] ?? 'collection::'.$collection->handle(),
            ]);

            return response()->json($exception->toArray(), 409);
        }
        try {
            $units = $walker->walk($blueprint->fields(), $data['values'], fn (Field $field) => ($field->config()['greenwashing_checker'] ?? true) !== false);
        } catch (UnexpectedValueException|JsonException $exception) {
            Log::warning('Greenwashing publish values could not be read.', ['exception' => $exception]);

            return response()->json(['message' => __('greenwashing-checker::messages.errors.invalid_values')], 422);
        }
        $rules = Settings::analysis($collection->handle());
        try {
            $plan = app(EntryScanner::class)->prepareUnits($units, $rules);
        } catch (UnitFileException $exception) {
            return response()->json(AiException::from($exception)->toArray(), 500);
        }
        ['hashes' => $hashes, 'cached' => $cached, 'targets' => $targets, 'context' => $context] = $plan;
        if ($targets === []) {
            try {
                $result = $cache->result($hashes, $cached);
            } catch (UnitFileException $exception) {
                return response()->json(AiException::from($exception)->toArray(), 500);
            } catch (ApprovalFileException $exception) {
                Log::error('greenwashing-checker: approval file could not be read', ['exception' => $exception]);

                return response()->json(AiException::from($exception)->toArray(), 500);
            }
            if ($entry !== null) {
                app(EntryIndex::class)->record($entry, $result);
            }
            $result['units'] = (object) $result['units'];

            return response()->json(['status' => 'completed', 'result' => $result]);
        }
        $id = $jobs->create('greenwashing-checker', $user->id());
        AnalyzeEntryJob::dispatch($id, array_values($targets), $context, $user->preferredLocale(), $site->locale(), $hashes, $cached, $data['reference'] ?? 'collection::'.$collection->handle(), $rules);

        return response()->json(['status' => 'queued', 'job' => $id, 'poll' => cp_route('greenwashing-checker.jobs.show', ['job' => $id])], 202);
    }
}
