<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Http\Controllers;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Http\EntryAccess;
use ElSchneider\GreenwashingChecker\Storage\ApprovalFileException;
use ElSchneider\GreenwashingChecker\Storage\ApprovalStore;
use ElSchneider\GreenwashingChecker\Storage\EntryIndex;
use ElSchneider\GreenwashingChecker\Storage\UnitCache;
use ElSchneider\GreenwashingChecker\Storage\UnitFileException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

final class ApprovalController
{
    public function __invoke(Request $request, UnitCache $cache, ApprovalStore $approvals): JsonResponse
    {
        [, , , $user] = EntryAccess::authorize($request);
        abort_unless($user->can('approve greenwashing findings'), 403);
        $revoke = $request->isMethod('delete');
        $data = $request->validate([
            'unit_hash' => ['required', 'string', 'regex:/^[a-f0-9]{64}$/'],
            'finding_id' => ['required', 'string', 'regex:/^[a-f0-9]{40}$/'],
            'reason' => $revoke ? ['sometimes', 'string'] : ['required', 'string', 'max:2000', 'regex:/\S/u'],
        ]);
        try {
            $known = in_array($data['finding_id'], array_column($cache->get($data['unit_hash'])['findings'] ?? [], 'id'), true);
            abort_unless($known || ($revoke && isset($approvals->all($data['unit_hash'])[$data['finding_id']])), 404);
            $approval = $revoke ? null : [
                'reason' => trim($data['reason']), 'user' => (string) $user->id(),
                'user_name' => $user->name() ?: $user->email(), 'at' => now()->toIso8601String(),
            ];
            $approvals->set($data['unit_hash'], $data['finding_id'], $approval);
            app(EntryIndex::class)->refreshDecision($data['unit_hash']);
        } catch (UnitFileException $exception) {
            return response()->json(AiException::from($exception)->toArray(), 500);
        } catch (ApprovalFileException $exception) {
            Log::error('greenwashing-checker: approval file could not be read', ['exception' => $exception]);

            return response()->json(AiException::from($exception)->toArray(), 500);
        }

        return response()->json(['approval' => $approval]);
    }
}
