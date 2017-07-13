<?php
declare(strict_types=1);

namespace RabbitCMS\Translations;

use Illuminate\Contracts\Translation\Translator as TranslatorInterface;
use Illuminate\Foundation\Application;
use RabbitCMS\Modules\ModuleProvider;
use RabbitCMS\Translations\Support\Translator;

/**
 * Class TranslationsServiceProvider
 *
 * @package RabbitCMS\Translations
 */
class TranslationsServiceProvider extends ModuleProvider
{
    /**
     * @return string
     */
    protected function name(): string
    {
        return 'translations';
    }

    public function register()
    {
        parent::registerMigrations();
        $this->app->extend('translator', function (TranslatorInterface $translator, Application $app) {
            return new Translator($translator, $app);
        });
    }
}
