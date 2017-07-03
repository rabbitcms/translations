<?php
declare(strict_types=1);

namespace RabbitCMS\Translations\Support;

use Illuminate\Contracts\Translation\Translator as TranslatorContract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Application;
use RabbitCMS\Translations\Entities\Translation;
use Illuminate\Translation\Translator as IlluminateTranslator;

/**
 * Class Translator
 *
 * @package RabbitCMS\Translations\Support
 */
class Translator extends IlluminateTranslator
{
    /**
     * Default laravel translator.
     *
     * @var \Illuminate\Translation\Translator
     */
    protected $translator;

    /**
     * The array of loaded translation groups.
     *
     * @var array
     */
    protected $loaded = [];

    /**
     * Application.
     *
     * @var Application
     */
    protected $app;

    /**
     * Create a new translator instance.
     *
     * @param TranslatorContract $translator
     */
    public function __construct(TranslatorContract $translator, Application $app)
    {
        $this->locale = $translator->getLocale();
        $this->fallback = $app['config']['app.fallback_locale'];
        $this->translator = $translator;
        $this->app = $app;
    }

    /**
     * @inheritdoc
     */
    public function setLocale($locale)
    {
        $this->translator->setLocale($locale);
        parent::setLocale($locale);
    }

    /**
     * @inheritdoc
     */
    public function setFallback($fallback)
    {
        $this->translator->setFallback($fallback);
        parent::setFallback($fallback);
    }

    /**
     * Get the translation for the given key.
     *
     * @param string $key
     * @param array $replace
     * @param string|null $locale
     * @param bool $fallback
     *
     * @return string|array|null
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        list($namespace, $group, $item) = $this->parseKey($key);

        $locales = $fallback ? $this->localeArray($locale)
            : [$locale ?: $this->translator->getLocale()];

        foreach ($locales as $lang) {
            if (null !== ($line = $this->getLine($namespace, $group, $lang, $item, $replace))) {
                return $line;
            }
        }

        return $key;
    }

    /**
     * Retrieve a language line out the database.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     * @param string $item
     * @param array   $replace
     *
     * @return string|array|null
     */
    protected function getLine($namespace, $group, $locale, $item, array $replace)
    {
        $this->load($namespace, $group, $locale);

        $line = $this->loaded[$namespace][$group][$locale][$item] ?? INF;

        $key = $namespace === '*' ? "{$group}.{$item}" : "{$namespace}::{$group}.{$item}";

        if ($line === null) {
            $line = $this->translator->get($key, $replace, $locale);
        }

        if ($line === INF) {
            $line = $this->translator->get($key, $replace, $locale);
            Translation::query()
                ->firstOrCreate([
                    'locale' => $locale,
                    'namespace' => $namespace,
                    'group' => $group,
                    'item' => $item
                ], [
                    'text' => $line === $key ? null : $line
                ]);
        }

        return $line === $key ? null : $this->makeReplacements($line, $replace);
    }

    /**
     * Load the specified language group.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     *
     * @return void
     */
    public function load($namespace, $group, $locale)
    {
        if ($this->isLoaded($namespace, $group, $locale)) {
            return;
        }

        if ($this->translationsAreCached($locale)) {
            $lines = $this->loadFromCache($locale);
        } else {
            $lines = Translation::query()
                ->where('locale', $locale)
                ->get()
                ->groupBy('namespace')
                ->transform(function (Collection $item) {
                    return $item->groupBy('group')
                        ->map(function (Collection $item) {
                            return $item->groupBy('locale')
                                ->map(function (Collection $item) {
                                    return $item->keyBy('item')
                                        ->map(function (Translation $translation) {
                                            return $translation->text;
                                        });
                                });
                        });
                })
                ->toArray();

            $cache_path = dirname($this->getCachedTranslationsPath($locale));
            if (!is_dir($cache_path)) {
                mkdir($cache_path, 0755);
            }

            $translations = var_export($lines, true);
            $this->app['files']->put($this->getCachedTranslationsPath($locale), "<?php\n return {$translations};\n");
        }

        $this->loaded = array_merge_recursive($this->loaded, $lines);
    }


    /**
     * Determine if the given group has been cached.
     *
     * @return bool
     */
    protected function translationsAreCached($locale): bool
    {
        return $this->app['files']->exists($this->getCachedTranslationsPath($locale));
    }

    /**
     * Get the path to the translations cache file.
     *
     * @return string
     */
    public function getCachedTranslationsPath($locale): string
    {
        return "{$this->app->bootstrapPath()}/cache/{$locale}/locales.php";
    }

    /**
     * @return array
     */
    protected function loadFromCache($locale): array
    {
        return require $this->getCachedTranslationsPath($locale);
    }
}
