<?php
declare(strict_types=1);

namespace RabbitCMS\Translations\Entities;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\App;
use RabbitCMS\Translations\Support\Translator;

/**
 * Class Translation
 *
 * @property-read int $id
 * @property string $locale
 * @property string $namespace
 * @property string $group
 * @property string $item
 * @property string $text
 * @property-read string $code
 */
class Translation extends Model
{
    protected $table = 'translations';

    protected $fillable = [
        'locale',
        'namespace',
        'group',
        'item',
        'text'
    ];

    /**
     * @return string
     */
    public function getCodeAttribute(): string
    {
        return $this->namespace === '*'
            ? "{$this->group}.{$this->item}"
            : "{$this->namespace}::{$this->group}.{$this->item}";
    }

    public static function boot()
    {
        static::saved(function (self $model) {
            $translator = App::make('translator');
            if ($translator instanceof Translator) {
                $translator->purgeCache($model->namespace, $model->group, $model->locale);
            }
        });

        parent::boot();
    }
}
