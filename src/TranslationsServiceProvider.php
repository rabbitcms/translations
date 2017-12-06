<?php
declare(strict_types=1);

namespace RabbitCMS\Translations;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Translation\Translator as TranslatorInterface;
use Illuminate\Support\ServiceProvider;
use RabbitCMS\Modules\ModuleProvider;
use RabbitCMS\Translations\Support\Translator;

/**
 * Class TranslationsServiceProvider
 *
 * @package RabbitCMS\Translations
 */
class TranslationsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->extend('translator', function (TranslatorInterface $translator, Application $app) {
            return new Translator($translator, $app);
        });
    }
}
