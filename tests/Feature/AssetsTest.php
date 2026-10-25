<?php

declare(strict_types=1);

use Statamic\Statamic;

it('publishes its built CP assets when statamic:install runs after composer install', function () {
    // Without published assets, every CP page fails with a missing Vite manifest.
    $command = new class
    {
        public array $calls = [];

        public function call(string $name, array $arguments = []): int
        {
            $this->calls[] = [$name, $arguments];

            return 0;
        }
    };

    Statamic::runAfterInstalledCallbacks($command);

    expect($command->calls)->toContain(['vendor:publish', ['--tag' => 'statamic-greenwashing-checker', '--force' => true]]);
});
