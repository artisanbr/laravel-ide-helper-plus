<?php

/**
 * Laravel IDE Helper Generator (Plus) - Based on Barry Vd. Laravel IDE Helper Generator
 *
 * @author    Renalcio Carlos Jr. <renalcio.c@gmail.com>
 * @author    Barry vd. Heuvel <barryvdh@gmail.com>
 * @license   http://www.opensource.org/licenses/mit-license.php MIT
 * @link      https://github.com/renalcio/laravel-ide-helper-plus
 */

namespace Renalcio\LaravelIdeHelperPlus;

use Renalcio\LaravelIdeHelperPlus\Console\IdeHelperCommand;
use Renalcio\LaravelIdeHelperPlus\Console\ModelsCommand;
use Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider as BaseServiceProvider;

class IdeHelperServiceProvider extends BaseServiceProvider
{
    protected static $configPath = __DIR__.'/../config/ide-helper-plus.php';
    /**
     * Bootstrap the application events.
     *
     * @return void
     */
    public function boot()
    {
        parent::boot();

        if (function_exists('config_path')) {
            $publishPath = config_path('ide-helper-plus.php');
        } else {
            $publishPath = base_path('config/ide-helper-plus.php');
        }
        $this->publishes([self::$configPath => $publishPath], 'config');
    }

    /**
     * Register the service provider.
     *
     * @return void
     */
    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(self::$configPath, 'ide-helper-plus');

        $this->app->singleton(
            'command.ide-helper.models',
            function ($app) {
                return new ModelsCommand($app['files']);
            }
        );

        $this->app->singleton(
            'command.ide-helper.all',
            function ($app) {
                return new IdeHelperCommand($app['files']);
            }
        );

        $this->commands(
            'command.ide-helper.all',
            IdeHelperCommand::class
        );
    }

    /**
     * Get the services provided by the provider.
     *
     * @return array
     */
    public function provides()
    {
        return array_merge(['command.ide-helper.all', 'command.ide-helper.models'], parent::provides());
    }
}
