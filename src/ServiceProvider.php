<?php

declare(strict_types=1);

namespace ElSchneider\GreenwashingChecker;

use ElSchneider\GreenwashingChecker\Ai\AiException;
use ElSchneider\GreenwashingChecker\Ai\AiModel;
use ElSchneider\GreenwashingChecker\Fieldtypes\GreenwashingChecker;
use ElSchneider\GreenwashingChecker\Listeners\InjectPanel;
use Illuminate\Support\Facades\Event;
use Statamic\Events\AddonSettingsSaving;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Facades\Collection;
use Statamic\Facades\Permission;
use Statamic\Facades\User;
use Statamic\Facades\YAML;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

final class ServiceProvider extends AddonServiceProvider
{
    protected $translations = false;

    protected $fieldtypes = [GreenwashingChecker::class];

    protected $commands = [Console\ScanCommand::class];

    protected $listen = [EntryBlueprintFound::class => [InjectPanel::class]];

    protected $routes = ['cp' => __DIR__.'/../routes/cp.php'];

    // Detects the cms capability the v6 code paths rely on. Apps may install Inertia on
    // Statamic 5 themselves, so the presence of Inertia alone is not a version signal.
    public static function supportsV6(): bool
    {
        return method_exists(\Statamic\CP\Utilities\Utility::class, 'inertia');
    }

    public function register(): void
    {
        parent::register();
        $this->app->singleton(Ai\TrackedJobs::class, fn ($app) => new Ai\TrackedJobs($app['cache.store']));
        $this->mergeConfigFrom(__DIR__.'/../config/greenwashing-checker.php', 'statamic.greenwashing-checker');
        $defaults = (require __DIR__.'/../config/greenwashing-checker.php')['jurisdictions'];
        $definitions = $defaults;
        foreach (config('statamic.greenwashing-checker.jurisdictions') as $key => $definition) {
            $definitions[$key] = array_replace($defaults[$key] ?? [], $definition);
            $definitions[$key]['references'] = array_replace($defaults[$key]['references'] ?? [], $definition['references'] ?? []);
        }
        config(['statamic.greenwashing-checker.jurisdictions' => $definitions]);
        $this->app->booted(function (): void {
            // Validate the published default even when a CP selection overrides it.
            Jurisdictions::validate([]);
            Jurisdictions::validate([
                'jurisdiction' => Settings::get('jurisdiction'),
                'jurisdiction_sites' => Settings::get('jurisdiction_sites'),
            ]);
        });
    }

    public function bootAddon(): void
    {
        // The settings-saving event (and the whole settings API) exists on Statamic 6 only.
        if (self::supportsV6()) {
            Event::listen(AddonSettingsSaving::class, function (AddonSettingsSaving $event): void {
                if ($event->settings->addon()->id() === 'el-schneider/statamic-greenwashing-checker') {
                    Jurisdictions::validate($event->settings->raw());
                }
            });
        }
        Permission::register('approve greenwashing findings')->label(__('greenwashing-checker::messages.approval_permission'));
        Permission::register('scan greenwashing findings')->label(__('greenwashing-checker::scan.permission'));
        // The native utility gate and navigation use this ability; one scan permission controls both.
        \Illuminate\Support\Facades\Gate::define('access greenwashing_scan utility', fn ($user) => $user->can('scan greenwashing findings'));
        \Statamic\Facades\Utility::extend(function () {
            $utility = \Statamic\Facades\Utility::register('greenwashing_scan')
                ->title(__('greenwashing-checker::messages.title'))
                ->description(__('greenwashing-checker::scan.description'))
                ->icon('magnifying-glass');
            $collections = fn () => Collection::all()->filter(fn ($collection) => Settings::collectionEnabled($collection->handle()))->map(fn ($collection) => ['handle' => $collection->handle(), 'title' => $collection->title()])->values();
            // Statamic 5 utilities render a Blade view instead of an Inertia page;
            // the JSON endpoints below are shared by both majors.
            if (self::supportsV6()) {
                $utility->inertia('GreenwashingScan', fn () => ['collections' => $collections()]);
            } else {
                $utility->view('greenwashing-checker::utility');
            }
            $utility->routes(function ($router) {
                $router->get('entries', [Http\Controllers\ScanController::class, 'index'])->name('entries');
                $router->get('preview', [Http\Controllers\ScanController::class, 'preview'])->name('preview');
                $router->post('start', [Http\Controllers\ScanController::class, 'start'])->name('start');
                $router->get('jobs/{job}', Ai\JobStatusController::class)->defaults('scope', 'greenwashing-checker-scan')->name('jobs.show');
            });
        });
        if (! self::supportsV6()) {
            return;
        }
        $this->app->bind('statamic.addons.statamic-greenwashing-checker.settings_blueprint', function (): array {
            $blueprint = YAML::file(__DIR__.'/../resources/blueprints/settings.yaml')->parse();
            $definitions = config('statamic.greenwashing-checker.jurisdictions');
            foreach ($definitions as $key => $definition) {
                $sources = array_map(fn (array $source) => '['.__($source['label']).']('.$source['url'].')', $definition['sources']);
                $blueprint['tabs']['legal_basis']['sections'][0]['fields'][] = [
                    'handle' => 'legal_basis_'.$key,
                    'field' => [
                        'type' => 'html', 'visibility' => 'computed', 'full_width_setting' => true,
                        'display' => __($definition['name']),
                        'instructions' => __('greenwashing-checker::settings.legal_basis_help')."\n\n- ".implode("\n- ", $sources),
                        'if' => ['jurisdiction' => 'equals '.$key],
                    ],
                ];
            }
            foreach ($blueprint['tabs'] as &$tab) {
                foreach ($tab['sections'] as &$section) {
                    foreach ($section['fields'] as &$field) {
                        $handle = $field['handle'];
                        $default = Settings::CP_ONLY_DEFAULTS[$handle] ?? config('statamic.greenwashing-checker.'.$handle);
                        $field['field']['default'] = $handle === 'collections' && $default === '*' ? ['*'] : $default;
                        if ($handle === 'collections') {
                            $field['field']['options'] = ['*' => __('greenwashing-checker::settings.all_collections')] + Collection::all()->mapWithKeys(fn ($collection) => [$collection->handle() => $collection->title()])->all();
                        }
                        if ($handle === 'jurisdiction') {
                            $field['field']['options'] = array_map(fn (array $definition) => __($definition['name']), $definitions);
                            $field['field']['validate'] = ['required', 'in:'.implode(',', array_keys($definitions))];
                        }
                        if ($handle === 'categories') {
                            $field['field']['options'] = collect(Analysis\Category::cases())->mapWithKeys(fn ($category) => [$category->value => $category->label().' — '.__('greenwashing-checker::settings.category_help.'.$category->value)])->all();
                            $field['field']['validate'] = ['array'];
                            $field['field']['validate'][] = 'in:'.implode(',', array_column(Analysis\Category::cases(), 'value'));
                        }
                    }
                }
            }

            return $blueprint;
        });
    }

    public function boot(): void
    {
        parent::boot();
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'greenwashing-checker');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'greenwashing-checker');
        $this->publishes([__DIR__.'/../config/greenwashing-checker.php' => config_path('statamic/greenwashing-checker.php')], 'greenwashing-checker-config');
        $this->publishes([__DIR__.'/../resources/views' => resource_path('views/vendor/greenwashing-checker')], 'greenwashing-checker-prompts');
        Statamic::provideToScript(['greenwashingChecker' => function (): array {
            try {
                $configured = AiModel::fromString(config('statamic.greenwashing-checker.model'))->isConfigured();
            } catch (AiException) {
                // An invalid model string is an explicit not-configured CP state.
                $configured = false;
            }

            return [
                'configured' => $configured,
                'canApprove' => User::current()?->can('approve greenwashing findings') ?? false,
                'onPublish' => Settings::get('on_publish'),
                'checkOnBlur' => (bool) Settings::get('check_on_blur'),
            ];
        }]);
    }

    protected function bootVite(): static
    {
        // One bundle per major: the v6 entry imports @statamic/cms/* (absent on v5),
        // the v5 entry uses runtime templates against Vue 2.7 and core CP classes.
        $this->registerVite([
            'input' => [self::supportsV6() ? 'resources/js/addon.ts' : 'resources/js/v5/addon.ts'],
            'publicDirectory' => 'resources/dist',
        ]);

        return $this;
    }
}
