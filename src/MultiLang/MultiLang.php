<?php
/*
 * This file is part of the Laravel MultiLang package.
 *
 * (c) Avtandil Kikabidze aka LONGMAN <akalongman@gmail.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Longman\LaravelMultiLang;

use Illuminate\Cache\CacheManager as Cache;
use Illuminate\Database\DatabaseManager as Database;
use Illuminate\Http\Request;
use InvalidArgumentException;

class MultiLang
{
    /**
     * Language/Locale.
     *
     * @var string
     */
    protected $lang;

    /**
     * System environment
     *
     * @var string
     */
    protected $environment;

    /**
     * The instance of the cache.
     *
     * @var \Illuminate\Cache\CacheManager
     */
    protected $cache;

    /**
     * Config.
     *
     * @var array
     */
    protected $config;

    /**
     * The instance of the database.
     *
     * @var \Illuminate\Database\DatabaseManager
     */
    protected $db;

    /**
     * Name of the cache.
     *
     * @var string
     */
    protected $cache_name;

    /**
     * Texts.
     *
     * @var array
     */
    protected $texts;

    /**
     * Missing texts.
     *
     * @var array
     */
    protected $new_texts;

    /**
     * Create a new MultiLang instance.
     *
     * @param string                               $environment
     * @param array                                $config
     * @param \Illuminate\Cache\CacheManager       $cache
     * @param \Illuminate\Database\DatabaseManager $db
     */
    public function __construct($environment, array $config, Cache $cache, Database $db)
    {
        $this->environment = $environment;
        $this->cache       = $cache;
        $this->db          = $db;

        $this->setConfig($config);
    }

    public function setConfig(array $config)
    {
        $this->config = $config;
        return $this;
    }

    public function getConfig($key = null, $default = null)
    {
        $array = $this->config;

        if ($key === null) {
            return $array;
        }

        if (array_key_exists($key, $array)) {
            return $array[$key];
        }

        foreach (explode('.', $key) as $segment) {
            if (is_array($array) && array_key_exists($segment, $array)) {
                $array = $array[$segment];
            } else {
                return $default;
            }
        }

        return $array;
    }

    /**
     * Get a cache driver instance.
     *
     * @return \Illuminate\Contracts\Cache\Repository
     */
    public function getCache()
    {
        if ($this->getConfig('cache.enabled', true) === false) {
            return null;
        }
        $store = $this->getConfig('cache.store', 'default');
        if ($store == 'default') {
            return $this->cache->store();
        }
        return $this->cache->store($store);
    }

    /**
     * Get a database connection instance.
     *
     * @return \Illuminate\Database\Connection
     */
    public function getDb()
    {
        $connection = $this->getConfig('db.connection');
        if ($connection == 'default') {
            return $this->db->connection();
        }
        return $this->db->connection($connection);
    }

    /**
     * Set locale and load texts
     *
     * @param  string $lang
     * @param  array  $texts
     * @return void
     */
    public function setLocale($lang, array $texts = null)
    {
        if (!$lang) {
            throw new InvalidArgumentException('Locale is empty');
        }
        $this->lang = $lang;

        $this->setCacheName($lang);

        if (!is_array($texts)) {
            $texts = $this->loadTexts($this->getLocale());
        }

        $this->texts = $texts;
    }

    /**
     * Load texts
     *
     * @param  string  $lang
     * @return array
     */
    public function loadTexts($lang = null)
    {
        if ($this->getCache() === null || $this->environment != 'production') {
            $texts = $this->loadTextsFromDatabase($lang);
            return $texts;
        }

        if ($this->mustLoadFromCache()) {
            $texts = $this->loadTextsFromCache();
        } else {
            $texts = $this->loadTextsFromDatabase($lang);
            $this->storeTextsInCache($texts);
        }

        return $texts;
    }

    /**
     * Get translated text
     *
     * @param  string   $key
     * @return string
     */
    public function get($key)
    {

        if (empty($key)) {
            throw new InvalidArgumentException('String key not provided');
        }

        if (!$this->lang) {
            return $key;
        }

        if (!isset($this->texts[$key])) {
            $this->queueToSave($key);
            return $key;
        }

        $text = $this->texts[$key];

        return $text;
    }

    /**
     * Get texts
     *
     * @return array
     */
    public function getRedirectUrl(Request $request)
    {
        $locale          = $request->segment(1);
        $fallback_locale = $this->getConfig('default_locale');

        if (strlen($locale) == 2) {
            $locales = $this->getConfig('locales');

            if (!isset($locales[$locale])) {
                $segments    = $request->segments();
                $segments[0] = $fallback_locale;
                $url         = implode('/', $segments);
                if ($query_string = $request->server->get('QUERY_STRING')) {
                    $url .= '?' . $query_string;
                }

                return $url;
            }
        } else {
            $segments = $request->segments();
            $url      = $fallback_locale . '/' . implode('/', $segments);
            if ($query_string = $request->server->get('QUERY_STRING')) {
                $url .= '?' . $query_string;
            }
            return $url;
        }

        return null;
    }

    public function detectLocale(Request $request)
    {
        $locale  = $request->segment(1);
        $locales = $this->getConfig('locales');

        if (isset($locales[$locale])) {
            return isset($locales[$locale]['locale']) ? $locales[$locale]['locale'] : $locale;
        }

        return $this->getConfig('default_locale', 'en');
    }

    /**
     * Get texts
     *
     * @return array
     */
    public function getTexts()
    {

        return $this->texts;
    }

    /**
     * Set texts manually
     *
     * @param  array                                 $texts_array
     * @return \Longman\LaravelMultiLang\MultiLang
     */
    public function setTexts(array $texts_array)
    {
        $texts = [];
        foreach ($texts_array as $key => $value) {
            $texts[$key] = $value;
        }

        $this->texts = $texts;

        return $this;
    }

    /**
     * Queue missing texts
     *
     * @param  string $key
     * @return void
     */
    protected function queueToSave($key)
    {
        $this->new_texts[$key] = $key;
    }

    /**
     * Check if we must load texts from cache
     *
     * @return bool
     */
    public function mustLoadFromCache()
    {
        return $this->getCache()->has($this->getCacheName());
    }

    protected function storeTextsInCache(array $texts)
    {
        $this->getCache()->put($this->getCacheName(), $texts, $this->getConfig('cache.lifetime', 1440));
        return $this;
    }

    public function loadTextsFromDatabase($lang)
    {
        $texts = $lang ? $this->getDb()->table($this->getTableName())
            ->where('lang', $lang)
            ->get(['key', 'value', 'lang', 'scope']) : $this->getDb()->table($this->getTableName())->get(['key', 'value', 'lang', 'scope']);

        $array = [];
        foreach ($texts as $row) {
            $array[$row->key] = $row->value;
        }
        return $array;
    }

    public function loadTextsFromCache()
    {
        $texts = $this->getCache()->get($this->getCacheName());

        return $texts;
    }

    public function setCacheName($lang)
    {
        $this->cache_name = $this->getConfig('db.texts_table') . '_' . $lang;
    }

    public function getCacheName()
    {
        return $this->cache_name;
    }

    public function getUrl($path)
    {
        $locale = $this->getLocale();
        if ($locale) {
            $path = $locale . '/' . $path;
        }
        return $path;
    }

    public function autoSaveIsAllowed()
    {
        if ($this->environment == 'local' && $this->getConfig('db.autosave', true) && $this->getDb() !== null) {
            return true;
        }
        return false;
    }

    public function getLocale()
    {
        return $this->lang;
    }

    public function saveTexts()
    {
        if (empty($this->new_texts)) {
            return false;
        }

        $table   = $this->getTableName();
        $locales = $this->getConfig('locales');

        foreach ($this->new_texts as $k => $v) {
            foreach ($locales as $lang => $locale_data) {
                $exists = $this->getDb()->table($table)->where([
                    'key'  => $k,
                    'lang' => $lang,
                ])->first();

                if ($exists) {
                    continue;
                }

                $this->getDb()->table($table)->insert([
                    'key'   => $k,
                    'lang'  => $lang,
                    'value' => $v,
                ]);
            }
        }
        return true;
    }

    protected function getTableName()
    {
        $table = $this->getConfig('db.texts_table', 'texts');
        return $table;
    }
}
