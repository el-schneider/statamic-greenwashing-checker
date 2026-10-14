<?php

declare(strict_types=1);

use ElSchneider\GreenwashingChecker\Ai\AiErrorCode;
use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\TrackedJobs;
use Statamic\Facades\User;

it('returns only the public payload to the authenticated Statamic owner', function () {
    $this->actingAs(User::make()->id('owner')->email('owner@example.test')->set('super', true));
    $jobs = app(TrackedJobs::class);
    $id = $jobs->create('greenwashing-checker', 'owner');
    $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertExactJson(['id' => $id, 'status' => 'queued']);
    $jobs->running($id);
    $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertExactJson(['id' => $id, 'status' => 'running']);
    $jobs->complete($id, ['findings' => []]);
    $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertExactJson(['id' => $id, 'status' => 'completed', 'result' => ['findings' => []]]);
    $error = new AiException(AiErrorCode::Unavailable);
    $jobs->fail($id, $error);
    $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertExactJson(['id' => $id, 'status' => 'failed', 'error' => $error->toArray()]);
});

it('does not leak jobs from another owner or scope or missing jobs', function () {
    $this->actingAs(User::make()->id('owner')->email('owner@example.test')->set('super', true));
    $jobs = app(TrackedJobs::class);
    foreach ([$jobs->create('greenwashing-checker', 'other'), $jobs->create('other-addon', 'owner'), 'missing'] as $id) {
        $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertNotFound()->assertJsonMissingPath('status')->assertJsonMissingPath('result');
    }
});

it('does not disclose a job to an unauthenticated request', function () {
    $id = app(TrackedJobs::class)->create('greenwashing-checker', 'owner');
    $this->getJson(cp_route('greenwashing-checker.jobs.show', ['job' => $id]))->assertUnauthorized()->assertJsonMissingPath('result');
});
