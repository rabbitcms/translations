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
     * @param string      $key
     * @param array       $replace
     * @param string|null $locale
     * @param bool        $fallback
     *
     * @return string|array|null
     */
    public function get($key, array $replace = [], $locale = null, $fallback = true)
    {
        list($namespace, $group, $item) = $this->parseKey($key);

        $locales = $fallback ? $this->localeArray($locale)
            : [$locale ?: $this->translator->getLocale()];

        foreach ($locales as $lang) {
            $line = $this->getRecord(
                $namespace,
                $group,
                $lang,
                $item,
                $replace,
                function () use ($key, $replace, $locale, $fallback) {
                    $line = $this->translator->get($key, $replace, $locale, $fallback);
                    return $line === $key ? null : $line;
                }
            );

            if (null !== $line) {
                return $line;
            }
        }

        return $key;
    }

    /**
     * Get the translation for a given key from the JSON translation files.
     *
     * @param string $key
     * @param array  $replace
     * @param string $locale
     *
     * @return string
     */
    public function getFromJson($key, array $replace = [], $locale = null): string
    {
        $locale = $locale ?: $this->locale;

        $this->load('*', '*', $locale);

        $line = $this->getRecord('*', '*', $locale, $key, $replace, function () use ($locale, $key, $replace) {
            $line = $this->translator->getFromJson($key, $replace, $locale);
            return $line === $key ? null : $line;
        });

        if (!isset($line)) {
            $fallback = $this->get($key, $replace, $locale);

            if ($fallback !== $key) {
                return $fallback;
            }
        }

        return $this->makeReplacements($line ?: $key, $replace);
    }

    /**
     * Retrieve a language line out the database.
     *
     * @param string   $namespace
     * @param string   $group
     * @param string   $locale
     * @param string   $item
     * @param array    $replace
     * @param callable $default
     *
     * @return array|null|string
     */
    protected function getRecord($namespace, $group, $locale, $item, array $replace, callable $default)
    {
        $this->load($namespace, $group, $locale);

        if ($item === null) {
            return $this->loaded[$namespace][$group][$locale] ?? [];
        }

        $line = $this->loaded[$namespace][$group][$locale][$item] ?? INF;

        if ($line === null) {
            $line = $default();
        }

        if ($line === INF) {
            $line = $default();
            Translation::query()->firstOrCreate([
                'locale' => $locale,
                'namespace' => $namespace,
                'group' => $group,
                'item' => $item
            ], [
                'text' => $line
            ]);
        }

        return $line ? $this->makeReplacements($line, $replace) : null;
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

        if ($this->translationsAreCached($namespace, $group, $locale)) {
            $lines = $this->loadFromCache($namespace, $group, $locale);
        } else {
            $lines = Translation::query()
                ->where(compact($namespace, $group, $locale))
                ->pluck('text', 'item')
                ->toArray();

            $this->storeToCache($namespace, $group, $locale, $lines);
        }

        $this->loaded[$namespace][$group][$locale] = $lines;
    }


    /**
     * Determine if the given group has been cached.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     *
     * @return bool
     */
    protected function translationsAreCached(string $namespace, string $group, string $locale): bool
    {
        return $this->app['files']->exists($this->getCachedTranslationsPath($namespace, $group, $locale));
    }

    /**
     * Get the path to the translations cache file.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     *
     * @return string
     */
    public function getCachedTranslationsPath(string $namespace, string $group, string $locale): string
    {
        return str_replace('//*', '', storage_path("framework/locales/{$locale}/{$namespace}/{$group}.php"));
    }

    /**
     * @param string $namespace
     * @param string $group
     * @param string $locale
     *
     * @return array
     */
    protected function loadFromCache(string $namespace, string $group, string $locale): array
    {
        return require $this->getCachedTranslationsPath($namespace, $group, $locale);
    }

    /**
     * Store locale to cache.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     * @param array  $data
     */
    protected function storeToCache(string $namespace, string $group, string $locale, array $data = [])
    {
        $files = $this->app->make('files');

        $dir = dirname($path = $this->getCachedTranslationsPath($namespace, $group, $locale));
        is_dir($dir) || $files->makeDirectory(dirname($path), 0755, true);
        $files->put($path, "<?php\n return " . var_export($data, true) . ";\n");
    }

    /**
     * Store locale to cache.
     *
     * @param string $namespace
     * @param string $group
     * @param string $locale
     * @param array  $data
     */
    public function purgeCache(string $namespace, string $group, string $locale)
    {
        $this->app->make('files')->delete($this->getCachedTranslationsPath($namespace, $group, $locale));
    }
    
    public function addNamespace($namespace, $hint)
    {
        $this->translator->addNamespace($namespace, $hint);
    }
}
