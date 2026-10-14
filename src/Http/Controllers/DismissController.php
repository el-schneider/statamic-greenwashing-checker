<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Http\Controllers;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Http\EntryAccess;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use ElSchneider\GreenwashingChecker\Storage\UnitFileException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class DismissController
{
    public function __invoke(Request $request, UnitCache $cache): JsonResponse
    {
        [, , , $user] = EntryAccess::authorize($request);
        $data = $request->validate(['unit_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'], 'finding_id' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/'], 'dismissed' => ['sometimes', 'boolean']]);
        $dismissed = (bool) ($data['dismissed'] ?? true);
        try {
            abort_unless($cache->setDismissed($data['unit_hash'], $data['finding_id'], (string) $user->id(), $dismissed), 404);
        } catch (UnitFileException $exception) {
            return response()->json(AiException::from($exception)->toArray(), 500);
        }

        app(\ElSchneider\GreenwashingChecker\Storage\EntryIndex::class)->refreshDecision($data['unit_hash']);

        return response()->json(['dismissed' => $dismissed]);
    }
}
