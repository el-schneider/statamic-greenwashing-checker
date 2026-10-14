<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Ai;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Statamic\Facades\User;

final class JobStatusController
{
    public function __invoke(Request $request, string $job, TrackedJobs $jobs): JsonResponse
    {
        $state = $jobs->find($job);
        $user = User::current();
        abort_if($state === null || $state->scope !== $request->route('scope') || $user === null || (string) $state->ownerId !== (string) $user->id(), 404);

        $payload = ['id' => $state->id, 'status' => $state->status->value];
        if ($state->result !== null) {
            $payload['result'] = $state->result;
        }
        if ($state->error !== null) {
            $payload['error'] = $state->error;
        }

        return response()->json($payload);
    }
}
