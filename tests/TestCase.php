<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker\Tests;

use ElSchneider\GreenwashingChecker\ServiceProvider;
use Prism\Prism\PrismServiceProvider;
use Statamic\Testing\AddonTestCase;
use Statamic\Testing\Concerns\PreventsSavingStacheItemsToDisk;

abstract class TestCase extends AddonTestCase
{
    use PreventsSavingStacheItemsToDisk {
        preventSavingStacheItemsToDisk as statamicPreventSavingStacheItemsToDisk;
    }

    protected string $addonServiceProvider = ServiceProvider::class;

    private string $indexDirectory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->indexDirectory = sys_get_temp_dir().'/greenwashing-index-'.\Illuminate\Support\Str::uuid();
        $this->app->instance(\ElSchneider\GreenwashingChecker\Storage\EntryIndex::class, new \ElSchneider\GreenwashingChecker\Storage\EntryIndex($this->indexDirectory));
        $this->app->instance(\ElSchneider\GreenwashingChecker\Storage\UnitCache::class, new \ElSchneider\GreenwashingChecker\Storage\UnitCache($this->indexDirectory.'/units'));
    }

    protected function tearDown(): void
    {
        // Statamic 5 has no addon settings API to clean up.
        $addon = \Statamic\Facades\Addon::get('el-schneider/statamic-greenwashing-checker');
        if (method_exists($addon, 'settings')) {
            $addon->settings()->delete();
        }
        $this->app['files']->deleteDirectory($this->indexDirectory);
        parent::tearDown();
    }

    // Statamic 5 derives this path from the test class file, which for Pest is an
    // eval'd class under vendor/pestphp, so a fresh install has no such directory.
    protected function preventSavingStacheItemsToDisk(): void
    {
        $this->fakeStacheDirectory = __DIR__.'/__fixtures__/dev-null';
        $this->statamicPreventSavingStacheItemsToDisk();
    }

    /** Statamic 5 lacks the addon settings API and the Inertia utility; those seams are v6-only. */
    protected function requireStatamic6(string $seam): void
    {
        if (! ServiceProvider::supportsV6()) {
            $this->markTestSkipped("Statamic 6 only: {$seam} does not exist on Statamic 5.");
        }
    }

    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PrismServiceProvider::class];
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);
        $app['config']->set('app.key', 'base64:ybcI9MKuhLnESRSuWDfnJQuohOXMBaynfbTC5Y5i1FE=');
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('statamic.sites.sites', ['default' => ['name' => 'Deutsch', 'locale' => 'de', 'url' => '/']]);
        if (getenv('GREENWASHING_EVAL') !== '1') {
            $app['config']->set('statamic.greenwashing-checker.model', 'openai/test-model');
            $app['config']->set('prism.providers.openai.api_key', 'fake-key');
            $app['config']->set('prism.providers.openrouter.api_key', '');
            $app['config']->set('prism.providers.anthropic.api_key', '');
        }
    }
}
