<?php

declare (strict_types = 1);

namespace BpFilter\Filter;

// use BpFilter\Handler\QueryFactory as BpFilterQuery;
// use BpFilter\Handler\QueryFactory\Query;
use BpFilter\Handler\Query\QueryBuilder as BpFilterQuery;

/**
 * Client interface for sending HTTP requests.
 */
abstract class AbstractFilter implements FilterInterface
{
    const ALLOWED_PROPERTIES = [];
    // const VERSION = '7';

    /** @var \modX $modx */
    public $modx;
    public $bp;
    // public $writeLog;
    public $pdoFetch;
    // public $pdoClass;
    //
    public $user;
    public $userProfile;
    public $userId;

    public $pageId = 0;
    public $offset = 0;
    public $limit = 1;
    public $allowLimit = [15, 30, 60];

    public $packages;

    public $result = [
        'page' => 0,
        'limit' => 0,
        'total' => null,
        'props' => null,
        'data' => null,
        'output' => '',
        'pagination' => '',
        'sql' => '',
    ];

    /** уникальный идентификатор приложения */
    public $appId = '';
    public $mainAppId = '';

    /** @var registryHelper */
    private $register = null;
    /** @var string класс регистра сообщений */
    private $registerClass = 'modDbRegister';
    /** @var string тема сообщений */
    private $registerTopic = 'bp_cocain';

    /** хеш запроса */
    public $requestHash = '';
    public $initialized = [];
    // public $config = [];
    protected $query_chain = ['ids' => [], 'union_queries' => [], 'union_queries_with_rowsnum' => [], 'union_sql' => ''];
    protected $query;
    protected $queryJoinTvs = [];
    // protected $class            = 'modResource';
    protected $properties = [];
    // protected $cache_uid_prefix = 'bp_filter_new_';

    /**
     * Префикс используется и для имен таблиц БД и в качестве имени основной папки кеша
     *
     * @var string
     */
    protected $temp_table_prefix = 'bp_cocain';
    protected $configFromCache = false;

    private $startTime;
    private $startMemory;
    private $modxQueryTime;
    private $modxExecutedQueries;

    // public $cacheKey            = 'bp_filter';

    /**
     * [__construct description]
     * @param modX  $modx   [description]
     * @param array $config [description]
     */
    public function __construct(\modX &$modx, array $config = [])
    {
        $this->modx = &$modx;
        $this->startTime = time();
        $this->startMemory = memory_get_usage(true);
        $this->modxQueryTime = $this->modx->queryTime;
        $this->modxExecutedQueries = $this->modx->executedQueries;

        $this->user = &$this->modx->user;
        $this->userProfile = $this->user->getOne('Profile');
        $this->userId = $this->modx->user->get('id');

        // $this->current_path = dirname(__FILE__) . '/';
        $corePath = $this->modx->getOption('bp.core_path', $config, MODX_CORE_PATH . 'components/bp/', true);

        $this->config = \array_merge([
            // 'appId'                => '' // можно передать и перегрузить свойство из расширяемого класса.
            'cacheKey' => '', // имя ключика обязательно передаем тк используется в чанках
            'dis_cacheTime' => 1, // много где используется именно с таким названием переменной
            // ммм использовал раньше как время хранения данных в сессии,
            // по идее можно сделать его бесконечным....

            'core_path' => $corePath,
            'base_path' => MODX_BASE_PATH,
            'tplPath' => $corePath . 'elements/chunks/',
            'elementsPath' => $corePath . 'elements/chunks/',
            'ctx' => $this->modx->context->key,

            'debug' => false,
            'saveStat' => true,

            // работа с кешированием результатов
            'cache_disable_in_debug' => false,
            'cache_key' => '', // ключ передается в основном через ajax
            'cache_part' => $this->temp_table_prefix, // родительская директория в которой храниться кеш, если не указана используется default
            'cache_time' => 1,
            //   'cache_handler'          => 'xPDOFileCache', // xPDOMemCache xPDOAPCCache xPDOWinCache

        ], $this->config, $config);

        $this->_testClass();

        return $this;
    }

    /**
     * Тестирование работы класса
     *
     * Так как пока юнит тестов нет обойдемся данными проверками
     *
     * @return void
     */
    public function _testClass()
    {
        if (!$this->temp_table_prefix) {
            throw new \Exception('Параметр "temp_table_prefix" - префикс временных таблиц не указан');
        }
        return;
    }

    /**
     * [initialize description]
     * @param  [type] $ctx    [description]
     * @param  array  $config [description]
     * @return [type]         [description]
     */
    public function initialize($ctx = 'web', $config = []): bool
    {
        // $this->modx->log(\modX::LOG_LEVEL_ERROR, 'b0');
        $isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && $_SERVER['HTTP_X_REQUESTED_WITH'] == 'XMLHttpRequest';

        if (!$this->bp = $this->modx->getService('bp')) {
            throw new \Exception('Can`t load service BP!');
        }

        // if ($isAjax) {
        // if ($isAjax || $ctx == 'mgr' || (defined('MODX_API_MODE') && MODX_API_MODE)){
        // я думаю что обработчики тоже могут использоваться при нахождении в менеджере
        // например при вызове из события.
        // if (isset($this->initialized[$ctx])) {
        //   return $this->initialized[$ctx];
        // }
        // $cacheKey = @$_POST['cacheKey'];
        // }

        // $this->appId = preg_replace("/[^A-Za-z0-9_\-]/u", '', ($_POST['appId'] ?? $config['appId'] ?? $this->appId));
        // $this->appId = preg_replace("/[^A-Za-z0-9_\-]/u", '', ($_POST['appId'] ?? $config['appId']));
        // echo __CLASS__;
        // echo (new \ReflectionClass($this))->getShortName();
        // var_dump(get_class($this));

        $this->mainAppId = $this->getMainAppId($_POST['appId'] ?? $config['appId']);
        $this->appId = $this->getAppId();
        // $this->appId .= $this->mainAppId . '_' . $this->modx->context->key;

        $this->register = clone $this->bp->register;
        $this->register->registry = null; // иначе будет не в тот топик сваливаться
        // echo($this->registerTopic . '/' . $this->mainAppId);
        // die();
        // $this->register->setDefaults($this->registerTopic . '_' . $this->mainAppId, $this->registerClass);
        $this->register->setDefaults($this->registerTopic, $this->registerClass);

        // очередной кастыль, при запуске из внешенго сниппета, например pdoPage
        // он передает свои параметры, которые перезаписывают родные.
        unset($config['elementsPath'], $config['tplPath']);

        $this->modx->log(\modX::LOG_LEVEL_INFO, 'initialize(){} getSessionStore appId: ' . $this->appId);

        if (!($this->config['debug'] && $this->config['cache_disable_in_debug'])) {
            $this->bp->unsetSessionStore($this->appId);
        }

        // if (!($this->config['debug'] && $this->config['cache_disable_in_debug'])
        // && !empty($storedConfig = $this->bp->getSessionStore($this->appId))) {
        if (!empty($storedConfig = $this->bp->getSessionStore($this->appId))) {
            $this->modx->log(\modX::LOG_LEVEL_INFO, 'getSessionStore() получил конфиг');
            // $this->pdoFetch->addTime('получил успешно');

            $this->config = \array_merge($storedConfig, $config);
            // $this->config = $storedConfig;
            // $this->config['cacheUid'] = $cacheUid;
            $this->configFromCache = true;
            // }
        } else {
            $this->modx->log(\modX::LOG_LEVEL_INFO, 'getSessionStore() не обнаружен конфиг в сессии');

            // $tmp_req = $_REQUEST;
            // unset($tmp_req['hash']);

            $this->config = \array_merge($this->config, $config);

            // может удалять лишнее?
            // unset ($tmp['total']) ... ;

            // echo "<pre>".print_r($tmp,1)."</pre>";
            // die();

            // т.к. при запуске данного сниппета из друго, pdoPage например, то при
            // переходе пагинации каждая страница будет новым некешируемым запросом
            // что дает генерацию разных id по одной ссылке, это не правильно,
            // необходимо ручками убрать эти динамические поля из расчета id
            // это кастыль, но он необходим для правильной работы кеширования

            // unset($tmp['page'],$tmp['offset'],$tmp['request'],$tmp['limit']); // limit ???
            // unset($tmp['page'],$tmp['offset'],$tmp['limit']); // limit ???

            // echo "<pre>";
            // print_r($tmp);
            // echo "<pre>";
            // $cacheUid = $this->cache_uid_prefix . $this->bp->cacheKeyGenerator($tmp);
            // ksort($tmp);  // в случае хранения конфига это необходимость при построении hash
            // $cacheUid = $this->cache_uid_prefix . md5(implode(',', $tmp));  // чтобы не плодить массивы в сессии
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, 'uID: ' . $cacheUid);
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, 'tmp: ' . var_export($tmp,1));
            $this->modx->log(\modX::LOG_LEVEL_INFO, 'initialize(){} setSessionStore appId: ' . $this->appId);
            // if ($this->bp->setSessionStore( $cacheUid, $this->config, $this->config['cache_time'] )){
            if (!$this->bp->setSessionStore($this->appId, $this->config)) {
                // обязательно до инициализации лога
                // т.к. он может прописать объект в log_target
                // в случае если вызов идет из консоли, а этого нам не надо.
                // $this->config['cacheUid'] = $cacheUid;
                $this->modx->log(\modX::LOG_LEVEL_ERROR, 'не смог сохранить данные в сессию, с uID: ' . $this->appId);
            }

            // ??? обновим конфиг хранилища, на случай если он используется в дальнейшем в ajax запросах
            // $this->setSessionStore( $this->config['cacheUid'], $this->config, $this->config['cache_time'] );

            // echo '</pre>';
        }

        /** используется в методах getCache, setCache, setTotal */
        $this->config['cache_key'] = $this->appId;
        // die();

        // инициализируем лог
        $this->bp->initializeLogging();

        $ctx = (isset($ctx) && $ctx == 'mgr')
        ? ((!$this->modx->context->key || $this->modx->context->key == 'mgr')
            ? 'web'
            : $this->modx->context->key)
        : trim($ctx);

        if (isset($this->initialized[$ctx])) {
            return true;
        }

        // $this->bp->initialize($this->config['ctx']); // либо loadPdoTools()
        $this->bp->loadPdoTools();
        $this->pdoFetch = &$this->bp->pdoTools;
        $this->pdoFetch->addTime('pdoTools loaded');
        // return true;

        // $this->config = array_merge($this->bp->config_pdo, $this->config); // не работает кастыль

        $this->initialized[$ctx] = true;
        $this->pdoFetch->setConfig($this->config, false);
        // распарсиваем значения поиска переданные при инициализации сниппета,
        // они будут использоваться при подсчете setTotal
        if (!empty($this->config['where'])) {
            $tmp = $this->config['where'];
            if (is_string($tmp) && ($tmp[0] == '{' || $tmp[0] == '[')) {
                $tmp = json_decode($tmp, true);
            }
            if (!is_array($tmp)) {
                $tmp = [$tmp];
            }
            // var_export($tmp);
            if (!empty($tmp)) {
                $this->config['where'] = $tmp;
            }
        }

        $this->pdoFetch->addTime("AppId: " . $this->appId);
        $this->pdoFetch->addTime("Конфиг: " . print_r($this->config, true));

        return true;
    }

    // public function addTime(...$args)
    // {
    //     return $this->pdoFetch->addTime($args);
    // }

    /**
     * Получение хранимых параметров
     * @return [type] [description]
     */
    public function getProperties(): array
    {
        return $this->properties;
    }

    /**
     * Сохраняет параметры
     * @param array $data [description]
     */
    public function setProperties(array $data = [])
    {
        $this->properties = $data;
    }

    public function hashGenerator(array $data = []): string
    {
        // $result = $this->appId;
        if (!empty($data)) {
            // $result .= '_' . $this->bp->cacheKeyGenerator($data);
            $result = $this->bp->cacheKeyGenerator($data);
        } else {
            $result = 'default';
        }
        return $result;
    }

    /**
     * Главный метод объекта
     * @return [type] [description]
     */
    public function process()
    {
        $this->beforeProcess();

        $time = microtime(true);
        // $this->pdoFetch->addTime('cache_uid: ' . $this->config['cacheUid']);
        $this->pdoFetch->addTime("Process();");
        $this->modx->exec('SET SQL_BIG_SELECTS = 1');
        // $this->pdoFetch->addTime('SQL prepared <small>"' . $this->query->toSQL() . '"</small>');

        $this->query = &$this->createQuery();
        $this->pdoFetch->addTime('createQuery()');

        $request_params = array_merge($_GET, $_POST);

        $this->pageId = $this->getPageId($request_params);
        $this->offset = $this->getOffset($request_params);
        $this->limit = $this->getLimit($request_params);

        $this->prepareProperties($request_params);
        $this->pdoFetch->addTime('prepareProperties()' . print_r($this->getProperties(), true));

        $this->requestHash = $this->hashGenerator($this->getProperties());
        $this->pdoFetch->addTime('generate requestHash: ' . $this->requestHash);

        // Формируем ключ кеша в с учетом пагинации и глубины погружения в пагинацию
        $key = 'pages' . '/' . $this->getDevider($this->pageId) . '/' . $this->pageId;
        $combined_config = $this->getCacheConfig($key);
        // echo '<pre>';
        // var_dump($combined_config);
        // die();

        // $this->bp->setCache('test', $combined_config);
        // exit();

        // unset ($tmp['cacheUid']);
        // unset ($tmp['req'],$tmp['cacheUid']);
        // unset($tmp['page'],$tmp['offset'],$tmp['request'],$tmp['limit']); // limit ???

        // $combined_config['cache_key'] = '../logs/error.log';  // забавный результат, в некоторых случая может помочь взлому
        // нужно удалять cache_key иначе всегда новый кеш будет ??? нет не нужно
        $time = microtime(true);

        // IDEA можно не сохранять default запросы...
        if ($this->config['saveStat']) {
            $this->saveRequest($this->getProperties(), $this->requestHash);
        }

        // if (($this->result = $this->bp->getCacheTest($combined_config, $cache_key)) && $this->result['output']) {
        if (!($this->config['debug'] && $this->config['cache_disable_in_debug'])
            && $this->result = $this->bp->getCache($combined_config)
        ) {
            $this->pdoFetch->addTime('взял данные из кеша');
            $this->getTotal();
        } else {
            $this->prepareQuery();
            // exit();
            $this->pdoFetch->addTime('prepareQuery()');

            $this->createCommonQuery($combined_config); // подключает сортировку (самый долгий метод)
            // $this->createCommonQuery($this->getProperties()); // подключает сортировку (самый долгий метод)
            // $this->pdoFetch->addTime('createCommonQuery()', microtime(true) - $time);$time = microtime(true);
            if ($this->getTotal() === false) {
                $this->setTotal();
            }
            // $this->pdoFetch->addTime('setTotal()', microtime(true) - $time);$time = microtime(true);

            if (!$this->addPackageCriteria($this->pageId)) {
                // если не используем пачки, тогда устанавливаем границы выборки
                $this->setLimit();
            }

            if (!empty($this->config['finalJoins'])) {
                $this->pdoFetch->addTime('finalJoins()');
                $this->addJoins($this->query, $this->config['finalJoins']);
            }

            // $this->pdoFetch->addTime('test 5', microtime(true) - $time);$time = microtime(true);
            $this->result['output'] = $this->run();
            // $this->pdoFetch->addTime('выполнил run()', microtime(true) - $time);$time = microtime(true);
            $this->pdoFetch->addTime('выполнил run()');
            $time = microtime(true);
            // $this->setTotal_old($combined_config);
            // $this->pdoFetch->addTime('setTotal_old', microtime(true) - $time);$time = microtime(true);

            $this->result['props'] = $combined_config;
            // $this->bp->setCache($this->result, $combined_config, $this->requestHash);
            $this->bp->setCache($this->result, $combined_config);
            // echo '<pre>';print_r($tmp);die();
            $this->pdoFetch->addTime('сохранил данные result в кеш', microtime(true) - $time);
        }

        $this->setFinalLogInfo();

        return $this->result['output'];
    }

    /**
     * Формирует appId
     *
     * @return string
     */
    public function getAppId(): string
    {
        return $this->mainAppId . '_' . $this->modx->context->key;
    }

    /**
     * Возвращает имя текущего класса
     *
     * @param string $requiredAppId имя для сравнения
     * @return string
     */
    public function getMainAppId(string $requiredAppId): string
    {
        $requiredAppId = strtolower(preg_replace("/[^A-Za-z0-9_\-]/u", '', $requiredAppId));
        $className = substr(strrchr(get_class($this), '\\'), 1);
        $className = strtolower(str_replace('Filter', '', $className));
        if ($requiredAppId !== $className) {
            throw new \Exception(__METHOD__ . ' [ERROR]: Приложение (' . $requiredAppId . ') не найденно');
        }
        return $className;

        // $time = microtime(true);
        // for ($i = 0; $i < 1000000; ++$i) {
        // $a=explode('\\', get_class($this));
        // $a=array_pop($a);
        // 0.35 sec

        // $a=(new \ReflectionClass($this))->getShortName();
        // 0.44 sec

        // $a=substr(strrchr(get_class($this), '\\'), 1);
        // 0.25 sec
        // }
        // var_dump([
        //     $a,
        //     $time,
        //     microtime(true),
        //     (microtime(true) - $time),
        // ]);
        // die();
    }

    private function setFinalLogInfo()
    {
        $totalTime = (microtime(true) - $this->startTime);
        $queryTime = $this->modx->queryTime - $this->modxQueryTime;
        $queries = isset($this->modx->executedQueries) ? $this->modx->executedQueries - $this->modxExecutedQueries : 0;
        $phpTime = $totalTime - $queryTime;
        $queryTime = sprintf("%2.4f s", $queryTime);
        $totalTime = sprintf("%2.4f s", $totalTime);
        $phpTime = sprintf("%2.4f s", $phpTime);
        $source = $this->modx->resourceGenerated ? "database" : "cache";
        $memoryTotal = number_format((memory_get_usage(true)) / 1024, 0, ",", " ") . ' kb';
        $memory = number_format((memory_get_usage(true) - $this->startMemory) / 1024, 0, ",", " ") . ' kb';

        $this->pdoFetch->addTime('запросов: ' . $queries);
        $this->pdoFetch->addTime('время на запросы: ' . $queryTime);
        $this->pdoFetch->addTime('время на php: ' . $phpTime);
        $this->pdoFetch->addTime('время на скрипт: ' . $totalTime);
        $this->pdoFetch->addTime('источник данных: ' . $source);
        $this->pdoFetch->addTime('php в памяти: ' . $memoryTotal);
        $this->pdoFetch->addTime('занимаемое место в памяти скриптом: ' . $memory);
    }

    /**
     * Сохраняет информацию в регистр о необходимости сброса кеша текущего приложения
     *
     * @return void
     */
    final public function setRegisterClearCache(?string $msgId = null, $data = ''): void
    {
        $topic = $this->mainAppId;
        $msgId = $msgId ?? $this->appId;
        // $tmp = 1;
        // $tmp = $this->getRegisterClearCache(false);
        // $tmp = array_merge($tmp, $data);
        // $result = $this->register->set($topic, [$msgId => $tmp], [
        $result = $this->register->set($topic, json_encode([$msgId => $data]), [
            // $result = $this->register->set($topic, [["beer1"], ["beer2"], ["beer3"]], [
            'ttl' => 0,
            'kill' => true,
            // 'delay' => 10
        ]);

        if (!$result) {
            throw new \Exception(__METHOD__ . ' [ERROR]: Can`t write data in registry');
            // } else {
            // $msg = "Данные сохранены в регистре сообщений: класс - {$this->register->config['registerClass']}, очередь - {$this->register->config['registerName']}, topic - {$topic}\n\r";
            // $this->bp->sendEmail('xoxol-1@yandex.ru', '[INFO] BP cron: setRegisterClearCache', $msg, '', $result, '', false);
        }
    }

    /**
     * Получает данные из регистра текущего приложения
     *
     * @param boolean $remove_read удаляет запись из регистра при чтении
     * @param boolean $every true возвращает массив со всеми записями, только при
     *                            remove_read === true
     * @param array $_result последние переданное значение при рекурсивном обходе
     * @return void
     */
    final public function getRegisterClearCache(bool $remove_read = false, bool $every = false, $_result = [])
    {
        // echo '<pre>';
        $topic = $this->mainAppId;
        if ($tmp = $this->register->get($topic, [
            'remove_read' => $remove_read,
            'include_keys' => true,
        ])) {
            // var_dump($tmp);
            $tmp = array_shift($tmp);
            // var_dump($tmp);
            if (strpos($tmp, '{') === 0) {
                $tmp = json_decode($tmp, true);
                // var_dump($tmp);
            }
            // var_dump();die();
            $_result[] = $tmp;
        };

        // var_dump([$remove_read, $every, !empty($tmp)]);

        if ($remove_read && $every && !empty($tmp)) {
            // echo 'OK';
            $_result = $this->getRegisterClearCache($remove_read, $every, $_result);
        }
        // echo '</pre>';

        return ($every ? $_result : array_shift($_result));
    }

    /**
     * Проверяет существование записи в регистре для сброса кеша текущего приложения
     *
     * @param boolean $remove_read удаляет запись из регистра при чтении
     * @return void
     */
    final public function hasRegisterClearCache(bool $remove_read = false): bool
    {
        return (!!($this->getRegisterClearCache($remove_read)));
    }

    /**
     * Производит сброс кеша и удаление соответствующих таблиц из БД
     *
     * @param string $cache_prefix|$this->appId При передаче параметра === '/'
     *               будет произведен сброс всего кеша текущего компонента
     * @return boolean
     */
    final public function removeCache(string $cache_prefix = '', bool $drop_tables = true): bool
    {
        // if (!$this->hasRegisterClearCache()) {
        //     return false;
        // }

        $cache_prefix = !empty($cache_prefix) ? $cache_prefix : $this->appId;
        $path = trim($this->config['cache_part'] . '/' . $cache_prefix, '/') . '/';

        $deleted = [];
        $abspath = $this->modx->getOption(\xPDO::OPT_CACHE_PATH) . $path;

        // echo "{$abspath}\r\n";
        $this->pdoFetch->addTime(__METHOD__ . ' abspath: ' . $abspath);

        if (file_exists($abspath)) {
            if (is_dir($abspath)) {
                if ($this->modx->cacheManager->deleteTree($abspath, ['deleteTop' => false, 'skipDirs' => false, 'extensions' => ['.cache.php']])) {
                    $this->pdoFetch->addTime(__METHOD__ . ' cache deleted succesfully');
                    $deleted[] = $abspath;
                }
            } else {
                if (unlink($abspath)) {
                    $deleted[] = $abspath;
                }
            }
        }

        if ($drop_tables) {
            $prefix_table_name = rtrim(str_replace('/', '_', $path), '_');
            if ($tables = $this->getTablesByPrefix($prefix_table_name)) {
                // var_export($tables);
                $this->dropTables($tables);
            } else {
                $this->pdoFetch->addTime(__METHOD__ . ' cache tables not found');
            }
        }

        if (!empty($deleted)) {
            // echo "Cache is cleared, deleted:\r\n" . print_r($deleted, true);
            $this->pdoFetch->addTime(__METHOD__ . " Cache is cleared, deleted:\r\n" . print_r($deleted, true));
        }

        return true;
    }

    final public function getDevider(int $num = 0, int $devider = 500): int
    {
        $num = (intval($num)) ?? 0;
        return intdiv($num, $devider);
    }

    public function getCacheConfig(?string $part = null)
    {
        if (!$part) {
            throw new \Exception('getCacheConfig: значение part не указанно');
        }

        $cache_key = $this->appId . '/' . $this->requestHash . '/' . trim($part, '/');
        return array_merge(
            $this->config,
            $this->getProperties(),
            ['cache_key' => $cache_key]
            // [
            //     'cache_prefix' => $this->appId . '/zzz/'
            // ],
        );
    }

    /**
     * [saveRequest description]
     * @param  array  $data [description]
     * @param  [type] $hash [description]
     * @return [type]       [description]
     */
    private function saveRequest($data = [], $hash = null)
    {
        $time = microtime(true);
        $hash = $hash ?? $this->bp->cacheKeyGenerator($data);
        // $data = ['id'=>2,'request'=>'{}','count'=>123];
        if ($bp_request = $this->modx->newObject('bpFilterQueries')) {
            if ($bp_request->addRequest($hash, $this->config['resourceId'], ['request' => $data], $this->appId)) {
                $this->pdoFetch->addTime("сохранил запрос в bpFilterQueries с хешем {$hash}: " . print_r($data, true), microtime(true) - $time);
            } else {
                $this->pdoFetch->addTime("не смог сохранить запрос в bpFilterQueries с хешем {$hash} см. error.log", microtime(true) - $time);
            }
        }
    }
    /**
     * Запускает сформированный запрос и возвращает итоговый результат
     * @return [type] [description]
     */
    private function run()
    {
        $this->pdoFetch->addTime("SQL prepared:\n\r" . $this->query->toSql());
        $time = microtime(true);
        $q = &$this->query->get_query();

        // echo '<pre>';
        // print_r($this->config);
        // $tmp_first_select = $q->query['columns'][0];
        // unset($q->query['columns']);

        // array_unshift($q->query['columns'], 'SQL_CALC_FOUND_ROWS');
        // $this->result['sql'] = $this->query->toSQL();
        // $q = $this->modx->prepare($this->result['sql']);
        // echo ($this->result['sql']); die();
        // echo ($this->query->toSQL());die();
        if ($q->stmt->execute()) {
            $this->modx->queryTime += microtime(true) - $time;
            $this->modx->executedQueries++;
            $this->pdoFetch->addTime('SQL executed', microtime(true) - $time);
            // $this->setTotal();
            $rows = $q->stmt->fetchAll(\PDO::FETCH_ASSOC);

            // echo count($rows); die();

            $this->pdoFetch->addTime('Rows fetched');
            // $rows = $this->checkPermissions($rows);
            $this->count = count($rows);
            $this->result['data'] = &$rows;

            switch (strtolower($this->config['return'])) {
                case 'ids':
                    $this->pdoFetch->addTime('Returning ids');
                    $ids = [];
                    foreach ($rows as $row) {
                        $ids[] = $row[$this->query->get_pk()];
                    }
                    $output = implode(',', $ids);
                    break;

                case 'data':
                    $this->pdoFetch->addTime('Returning raw data');
                    $rows = $this->prepareRows($rows);
                    $output = &$rows;
                    break;

                case 'json':
                    $this->pdoFetch->addTime('Returning raw data as JSON string');
                    $rows = $this->prepareRows($rows);
                    $output = json_encode($rows);
                    break;

                case 'serialize':
                    $this->pdoFetch->addTime('Returning raw data as serialized string');
                    $rows = $this->prepareRows($rows);
                    $output = serialize($rows);
                    break;

                case 'chunks':
                default:
                    $rows = $this->prepareRows($rows);
                    $time = microtime(true);
                    $output = [];
                    $result = [];
                    // $ctx_config = $this->bp->getContextSettings('', $this->config['ctx']);

                    // $default_data = array(
                    //   'ctx_config' => $ctx_config,
                    //   'page' => $this->config['page']
                    // );

                    foreach ($rows as &$row) {
                        if (!empty($this->config['additionalPlaceholders'])) {
                            $row = array_merge($this->config['additionalPlaceholders'], $row);
                        }
                        $row['idx'] = $this->idx++;

                        // Add placeholder [[+link]] if specified
                        if (!empty($this->config['useWeblinkUrl'])) {
                            if (!isset($row['context_key'])) {
                                $row['context_key'] = '';
                            }
                            if (isset($row['class_key']) && ($row['class_key'] == 'modWebLink')) {
                                $row['link'] = isset($row['content']) && is_numeric(trim($row['content'], '[]~ '))
                                ? $this->pdoFetch->makeUrl(intval(trim($row['content'], '[]~ ')), $row)
                                : (isset($row['content']) ? $row['content'] : '');
                            } else {
                                $row['link'] = $this->pdoFetch->makeUrl($row['id'], $row);
                            }
                        } else {
                            $row['link'] = '';
                        }

                        // $tpl = $this->pdoFetch->defineChunk($row);
                        // $result_rows[] = $row = array_merge($default_data, $row);
                    }

                    $tpl = $this->config['tpl'] ?? '';
                    // echo $tpl; die();
                    // var_export($result);
                    // die();

                    $result['config'] = $this->config;
                    $result['rows'] = $rows;
                    // $output[] = '';
                    $output[] = $this->pdoFetch->getChunk($tpl, $result, $this->config['fastMode']);
                    $this->pdoFetch->addTime('Returning processed chunks', microtime(true) - $time);

                    if (!empty($this->config['toSeparatePlaceholders'])) {
                        $this->modx->setPlaceholders($output, $this->config['toSeparatePlaceholders']);
                        $output = '';
                    } else {
                        $output = implode($this->config['outputSeparator'], $output);
                        // $this->pdoFetch->addTime('Wrap result into chunk $tplWrap', microtime(true) - $time);
                        // $output = $this->pdoFetch->getChunk($this->config['tplWrap'], array('output' => $output), $this->config['fastMode']);
                    }
                    break;
            }
        } elseif ($errors = $q->stmt->errorInfo()) {
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, '[bpIfilters] ' . $this->query->toSQL());
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, '[bpIfilters] Error ' . $errors[0] . ': ' . $errors[2]);
            // $this->pdoFetch->addTime('Could not process query, error #' . $errors[1] . ': ' . $errors[2]);
            throw new \Exception('run()[ERROR]:Could not process query, error #' . $errors[1] . ': ' . $errors[2]);
        } else {
            $this->pdoFetch->addTime('Данные по запросу не найдены');
        }
        // $this->modx->log(\modX::LOG_LEVEL_ERROR, '[bpIfilters] ' . $this->query->toSQL());
        $this->result['output'] = &$output;
        return $output;
    }

    /**
     * Создает объект с классом xPDOQuery для указанного класса
     * @param  string  $className The class to create the xPDOQuery for.
     * @return \xPDOQuery The resulting xPDOQuery instance or false if unsuccessful.
     */
    public function createQuery($className = ''): BpFilterQuery
    {
        $className = $className ?: $this->config['className'];
        $query = new BpFilterQuery($this->modx, $this->pdoFetch);
        $query->create($className);
        return $query;
    }

    /**
     * Устанавливает и возвращает страницу в соответствии
     * с переданными критериями.
     *
     * Обязательно уничтожаем это переменную в переданном массиве
     * для исключения его из расчета хеш суммы
     *
     * @param array $data
     * @return integer
     */
    public function getPageId(array &$data = []): int
    {
        $pageId = 1;
        if ($data['page']) {
            $pageId = $data['page'];
            unset($data['page']);
        }
        return (int) $pageId;
    }

    /**
     * Устанавливает и возвращает offset в соответсвии
     * с переданными критериями.
     *
     * Обязательно уничтожаем это переменную в переданном массиве
     * для исключения его из расчета хеш суммы
     *
     * @param array $data
     * @return integer
     */
    public function getOffset(array &$data = []): int
    {
        $offset = 0;
        if ($data['offset']) {
            $offset = $data['offset'];
            unset($data['offset']);
        }
        return $offset;
    }

    /**
     * Устанавливает и возвращает limit в соответсвии
     * с переданными критериями.
     *
     * Обязательно уничтожаем это переменную в переданном массиве
     * для исключения его из расчета хеш суммы
     *
     * @param array $data
     * @return integer
     */
    public function getLimit(array &$data = []): int
    {
        $limit = 0;
        if ($data['limit']) {
            $limit = $data['limit'];
            // unset($data['limit']);
        }
        $limit = $limit ?? $this->config['limit'];
        $limit = $this->getLimitVal((int) $limit);
        return $limit;
    }

    /**
     * Подготавливает свойства для последующего использования в формировании
     * запроса
     * @param  array  $data [description]
     * @return [type]       [description]
     */
    public function prepareProperties(array &$data = []): array
    {
        $result = [];
        // пройдемся по всем входящим параметрам

        foreach ($data as $key => &$value) {
            if (!in_array($key, $this::ALLOWED_PROPERTIES)) {
                continue;
            }
            // тут фильтруем через sinitize
            $key = $this->bp->sanitize($key);
            $value = &$this->bp->sanitize($value);
            if (!isset($value) || $value === '') {
                continue;
            }
            $result[$key] = $value;
        }
        $this->setProperties($result);
        return $result;
    }

    /**
     * Подготавливает критерии фильтрации, по данным
     * переданным через GET или POST
     * @return [type]        [description]
     */
    public function prepareQuery()
    {
        $time = microtime(true);
        $this->pdoFetch->addTime("prepareQuery();");
        if (!$this->query) {
            throw new \Exception('prepareQuery()[ERROR]: Query is not exist!');
        }

        // не будем мудрить как в pdofetch дадим возможность
        // простейшей выборки через запятую основных полей без тв-шек
        if ($this->config['return'] == 'ids') {
            $fields = [$this->query->get_pk()];
            $fields = $this->modx->getSelectColumns($this->config['className'], $this->config['className'], '', $fields);
        } else {
            if ($this->config['select']) {
                // echo 777;
                // $fields = array_map('trim', explode(',', $this->config['select']));
                $fields = $this->addSelects();
                // var_dump($r);
                // die();
            } else {
                $fields = $this->modx->getFields($this->config['className']);
                if (!$this->config['includeContent']) {
                    unset($fields['content']);
                }
                $fields = array_keys($fields);
                $fields = $this->modx->getSelectColumns($this->config['className'], $this->config['className'], '', $fields);
            }
        }

        $this->query->select($fields);
        // print_r($fields);die();
        // $fields = $this->modx->getSelectColumns($this->config['className'], $this->config['className'], '', array('id'));
        // array_unshift($fields, 'SQL_CALC_FOUND_ROWS');

        //
        // if ($this->config['setTotal']) {
        // $fields = 'SQL_CALC_FOUND_ROWS ' . $fields;
        // $fields = 'ids.rownum, ' . $fields;
        // }
        // $this->query->select('SQL_CALC_FOUND_ROWS ' . $fields);

        // нужно отдельно подключить все TV
        $tvs = array_map('trim', explode(',', $this->config['includeTVs']));
        $tvs = array_unique($tvs);

        if (!empty($tvs)) {
            $tvs_clear = array_map(function ($name) {
                // FIXME это для чего такая фильтрация???
                if (preg_match('/([^\(\)]*)(?:\((.*)\))?$/si', $name, $match)) {
                    $name = trim($match[1]);
                    // $cast = $match[2] ? str_replace([' ','(',')'], '', $match[2]) : null;
                }
                return $name;
            }, $tvs);

            // получаем все TV
            $this->pdoFetch->addTime('Строю запрос на получения данных о TVs: ' . var_export($tvs_clear, true));
            $tv_list_q = $this->createQuery('modTemplateVar');
            $tv_list_q->select($this->modx->getSelectColumns('modTemplateVar', 'modTemplateVar', '', ['id', 'name']));
            $tv_list_q->addWhere(['name:IN' => $tvs_clear]);
            // $tvs_list_result = [];
            // $tmp = $tv_list_q->toSql();
            // echo $tmp;

            $tmp_q = &$tv_list_q->get_query();
            // var_export($tmp_q);
            if ($tmp_q->prepare() && $tmp_q->stmt->execute()) {
                $this->modx->queryTime += microtime(true) - $time;
                $this->modx->executedQueries++;
                $this->pdoFetch->addTime('SQL executed TVs List', microtime(true) - $time);
                $time = microtime(true);
                // $this->setTotal();
                if ($tvs_list = $tmp_q->stmt->fetchAll(\PDO::FETCH_ASSOC)) {
                    $this->pdoFetch->addTime(print_r($tvs_list, true), microtime(true) - $time);
                    $time = microtime(true);
                    foreach ($tvs_list as $tv) {
                        $this->queryJoinTvs[$tv['name']] = $tv;
                        $this->query->joinTvById($tv['id'], $tv['name'], @$this->config['tvPrefix'], !($this->config['return'] == 'ids'));
                        // print_r($tv);
                        // $this->query->joinTvById($tv['id'], $tv['name'], @$this->config['tvPrefix'], !($this->config['return'] == 'ids'));
                    }
                    unset($tv);
                }
            }
        }

        // FIXME или тут делать присоединения JOIN ??? Вынести в отдельный метод
        $this->pdoFetch->addTime('Добавляю присоединение JOIN', microtime(true) - $time);
        $time = microtime(true);
        $this->addJoins($this->query);
        $this->pdoFetch->addTime('добавил присоединение JOIN', microtime(true) - $time);

        // FIXME выключил добавление WHERE так как засунул его в createChainSubQuery
        /* $this->pdoFetch->addTime('Добавляю критериии WHERE', microtime(true) - $time);
        $time = microtime(true);

        // сливаем параметры по умолчанию с параметром where
        // if (!empty($this->config['where'])) $this->config['where'] = [];
        // FIXME хранить ли в таком виде $this->config['where'] ???
        if (!empty($this->config['where'])) {
        if ($where = $this->pdoFetch->additionalConditions($this->config['where'])) {
        $where = array_merge($this->config['where_default'], $where);
        $this->query->addWhere($where);
        }
        } else {
        $this->query->addWhere($this->config['where_default']);
        }

        $data = $this->getProperties();
        // print_r($data);
        // die();

        // пройдемся по всем входящим параметрам
        foreach ($data as $criteria_name => &$value) {
        // тут фильтруем через sinitize
        // $props[$key] = $value;

        if (is_string($value) && trim($value) == '') {
        continue;
        }
        $this->addQueryCriteria($this->query, $criteria_name, $value);
        } */

        return $this;
    }

    // FIXME разнести в query addSelects???
    public function addSelects()
    {
        // $q_query = &$this->query->get_query();

        $time = microtime(true);
        $result = [];

        $tmp = $this->config['select'];
        if (!is_array($tmp)) {
            $tmp = (!empty($tmp) && $tmp[0] == '{' || $tmp[0] == '[')
            ? json_decode($tmp, true)
            : array($this->config['class'] => $tmp);
        }
        if (!is_array($tmp)) {
            $tmp = array();
        }

        // var_dump($tmp);

        $i = 0;
        foreach ($tmp as $class => $fields) {
            if (is_numeric($class)) {
                $class = $alias = $this->config['className'];
            } else {
                $alias = $class;
            }
            if (is_string($fields) && !preg_match('/\b' . $alias . '\b|\bAS\b|\(|`/i',
                $fields) && isset($this->modx->map[$class])
            ) {
                if ($fields == 'all' || $fields == '*' || empty($fields)) {
                    $fields = $this->modx->getSelectColumns($class, $alias);
                } else {
                    $fields = $this->modx->getSelectColumns($class, $alias, '',
                        array_map('trim', explode(',', $fields)));
                }
            }
            // var_dump($fields);

            if (is_string($fields) && strpos($fields, '(') !== false) {
                // Commas in functions
                $fields = preg_replace_callback('/\(.*?\)/', function ($matches) {
                    return str_replace(",", "|", $matches[0]);
                }, $fields);
                $fields = explode(',', $fields);
                foreach ($fields as &$field) {
                    $field = str_replace('|', ',', $field);
                }
                $result[] = $fields;
                // $q_query->select($fields);
                $this->pdoFetch->addTime('Added selection of <b>' . $class . '</b>: <small>' . str_replace('`' . $alias . '`.',
                    '', implode(',', $fields)) . '</small>', microtime(true) - $time);
            } else {
                $result[] = $fields;

                // $q_query->select($fields);
                if (is_array($fields)) {
                    $fields = current($fields) . ' AS ' . current(array_flip($fields));
                }
                $this->pdoFetch->addTime('Added selection of <b>' . $class . '</b>: <small>' . str_replace('`' . $alias . '`.',
                    '', $fields) . '</small>', microtime(true) - $time);
            }

            $i++;
            $time = microtime(true);
        }
        return implode(',', $result);
    }

    /**
     * Add tables join to query
     */
    // FIXME разнести в query addJoins???
    public function addJoins(&$query, $config = null)
    {
        // $q_query = &$this->query->get_query();
        $q_query = &$query->get_query();

        // $config = $config ?? $this->config;
        $config = $config ? $config : $this->config;

        $time = microtime(true);
        // left join is always needed because of TVs
        if (empty($config['leftJoin'])) {
            $config['leftJoin'] = '[]';
        }

        $joinSequence = array('innerJoin', 'leftJoin', 'rightJoin');
        if (!empty($config['joinSequence'])) {
            if (is_string($config['joinSequence'])) {
                $config['joinSequence'] = array_map('trim', explode(',', $config['joinSequence']));
            }
            if (is_array($config['joinSequence'])) {
                $joinSequence = $config['joinSequence'];
            }
        }

        foreach ($joinSequence as $join) {
            if (!empty($config[$join])) {
                $tmp = $config[$join];
                if (is_string($tmp) && ($tmp[0] == '{' || $tmp[0] == '[')) {
                    $tmp = json_decode($tmp, true);
                }
                // if ($join == 'leftJoin' && !empty($config['tvsJoin'])) {
                //     $tmp = array_merge($tmp, $config['tvsJoin']);
                // }
                foreach ($tmp as $k => $v) {
                    $class = !empty($v['class']) ? $v['class'] : $k;
                    $alias = !empty($v['alias']) ? $v['alias'] : $k;
                    $on = !empty($v['on']) ? $v['on'] : array();

                    if (!is_numeric($alias) && !is_numeric($class)) {
                        if (!empty($v['onSelect'])) {
// var_export($v);
// die();
                            $this->query->joinCustomTable([
                                'name'      => $class,
                                'alias'     => $alias,
                                'type'      => $join,
                                'addSelect' => false,
                                'onSelect'  => $v['onSelect'],
                                'on'        => $on,
                            ]);
                        } else {
                            $q_query->$join($class, $alias, $on);
                        }
                        $this->pdoFetch->addTime($join . 'ed <i>' . $class . '</i> as <b>' . $alias . '</b>', microtime(true) - $time);
                        $this->aliases[$alias] = $class;
                    } else {
                        $this->pdoFetch->addTime('Could not ' . $join . ' <i>' . $class . '</i> as <b>' . $alias . '</b>', microtime(true) - $time);
                    }
                    $time = microtime(true);
                }
            }
        }
    }

    public function getTvDataByName($name = '')
    {
        if ($name == '' || !isset($this->queryJoinTvs[$name])) {
            return false;
        }
        return ($this->queryJoinTvs[$name]);
    }

    /**
     * {@inheritDoc}
     */
    public function prepareRows(?array $rows): array
    {
        return $rows;
    }

    public function addPackageCriteria(int $page = 1)
    {
        $result = false;
        // $this->pdoFetch->addTime("YYY" . print_r($this->packages,1));
        // $this->pdoFetch->addTime("XXX" . print_r($props,1));
        $this->pdoFetch->addTime("Добавляю выборку по пакетам для страницы: ${page}");
        if ($this->packages && $page && $this->packages[$page]) {
            $this->query->addWhere(["id:IN" => $this->packages[$page]]);
            $result = true;
            $this->pdoFetch->addTime(" - взял из данных кеша");
        } else {
            // если по какойто причине нет пачек, можно приделать filter.page
            // и тут делать доп выборку. Но для этого нужно точно знать дефолтный лимит.
            $this->query->addWhere(["filter.page:=" => $page]);
            // $this->query->addWhere(["filter.page:=" => $this->packages[$page]]);
            // $this->query->addWhere(["filter:page="=>$this->packages[$props['page']],'OR:filter:page2='=>NULL]);
            $this->pdoFetch->addTime(" - нет данных, добавил критерий в запрос выборки");
            // die();
        }
        return $result;
    }

    /**
     * Добавляет сортировку и критерии поиска к запросу по сформированным
     * ранее идентификаторам
     * @return
     */
    public function createCommonQuery(array $props = [])
    {
        // вытаскиваем общий список идентификторов
        // $query->sortby('FIELD(modResource.id, 4,7,2,5,1 )');
        // if (!$union_query = $this->createChainSubQuery()) return false;
        if (!$this->createChainSubQuery()) {
            return false;
        }
        // $query = &$this->query->get_query();
        $query = &$this->query;

        // $sql = $this->query->toSQL();
        // echo $sql;
        // die();

        // print_r($this->query_chain['ids']);die();
        // добавляем сортировку по ids
        // echo '<pre>';
        // echo $this->query_chain['union_sql'];die();
        // print_r($props);
        // die;

        // ограничиваем выборку по тем же идентификторам
        // if ($this->query_chain['ids'])
        // $ids = $this->query_chain['ids'];
        // echo (count($ids)); die();

        // $temp_table_name = $this->getTempFilterTableName();
        // // используем вариант выборки по временной таблице
        // $this->query->joinCustomTable($temp_table_name, 'filter', '', false, 'innerJoin', [
        //   'sql' => "{$this->config['className']}.id = filter.id",
        // ]);

        // самый медленный вариант выборки
        // $this->query->addWhere(["id IN ({$this->query_chain['union_sql']})"]);

        // теоретически самый быстрый вариант выборки
        // $query->query['from']['joins'][] = array (
        //   'table' => "({$this->query_chain['union_sql']})",
        //   'class' => '',
        //   'alias' => 'ids',
        //   'type'  => 'INNER JOIN', //xPDOQuery::SQL_JOIN_CROSS,
        //   'conditions' =>
        //     new xPDOQueryCondition(array(
        //       'sql'         => "ids.id = {$this->config['className']}.id",
        //       'conjunction' => 'AND'
        //     )
        //   )
        // );

        // echo 123;
        // var_dump($query->query);die();
        $temp_table_name = $this->getTempFilterTableName($this->requestHash);

        $this->addSortQueryCriteria($this->query, $temp_table_name, $props);

        // $this->addJoins($this->query, $temp_table_name, $props);

        $this->query->toSQL(); // обязательный вызов метода toSQL иначе запрос не будет подготовлен

        // подготовить данные и разместить их в кеше в соответствии с выбранной страницей
        //
        // нужно джоинить TV параметры только те которые указаны в выборке критериев,
        // по крайней мере для получения этих данных, таким образом ускорим выборку
        // Можно делать паралельный запрос, где TV подключается только при выполнении switch
        //
        // $this->pdoFetch->addTime("SQL разбиения на страницы:\n\r" . $sql);

        $time = microtime(true);

        $cache_config = $this->getCacheConfig('packages');

        // unset ($cache_config['offset'],$cache_config['request'],$cache_config['page'],$cache_config['cacheUid'],$cache_config['cache_key']);
        // $props['cache_prefix'] .= '_total';

        if (!$packages = $this->bp->getCache($cache_config)) {
            $q = clone $query;
            // $q_query = &($q->get_query())->query;
            $q_query = &$q->get_query();
            unset($q_query->query['columns']);

            // FIXME может устанавливать limit вначале и вырезать вместе с офсетом???
            // $limit = $this->getLimitVal($cache_config);
            $limit = $this->limit;
            // $q_query->query['columns'] = ["filter.idx as idx", "filter.id as id", "(filter.idx DIV {$limit})+1 as page"];
            $q_query->query['columns'] = ["filter.id as id"];

            // echo $q->toSQL();
            // print_r($q->getScopeTVs());

            $q_query->query['from']['joins'] = [];
            // используем вариант выборки по временной таблице
            // var_dump($temp_table_name);
            // var_export($cache_config); die();
            $q->joinCustomTable([
                'name'         => $temp_table_name,
                'alias'        => 'filter',
                'alias_prefix' => '',
                'addSelect'    => false,
                'type'         => 'innerJoin',
                'conditions'   => ['sql' => "{$this->config['className']}.id = filter.id"]
            ]);


            // FIXME: тут нужно присоединить все джоины из конфига

            // echo ($this->pdoFetch->getTime());
            // die();

            $this->pdoFetch->addTime(" - ScopeTVs before");
            if ($tvs = $q->getScopeTVs()) {
                $this->pdoFetch->addTime(" - ScopeTVs exist");

                // в запрос были переданны критерии затрагивающие TV параметры
                // т.к. нам нужно всего лишь построить идентификаторы разбитые по пачкам
                // а не значения TV параметров, то для ускорения выборки, оставим в запросе
                // только используемые TV параметры
                // unset($q_query->query['from']['joins']);
                // $target= & $this->query['from']['joins'];
                // var_dump($q->joinTvById);
                foreach ($tvs as $tv) {
                    $this->pdoFetch->addTime(" - ScopeTVs joinTvById: " . $tv['id']);
                    $q->joinTvById($tv['id'], $tv['name'], @$this->config['tvPrefix'], false);
                    // echo ($q->toSql());
                    // var_dump($z);
                }
                // print_r($q->joinTvById);
            }
            $q_query->prepare();
            // print_r($q_query->query);

            $sql_with_row_number = "SELECT @rownum:=@rownum+1 rownum, result.id, ((@rownum-1) DIV {$limit})+1 as page FROM ({$q_query->toSql()}) as `result`, (SELECT @rownum := 0) r;";
            // echo $sql_with_row_number; die();

            // echo $q_query->toSQL();
            // $this->pdoFetch->addTime("SQL разбиения на пачки:\n\r" . $q_query->toSql());
            $this->pdoFetch->addTime("SQL разбиения на пачки:\n\r" . $sql_with_row_number);
            $total = null;
            if ($rows = $this->modx->query($sql_with_row_number)) {
                // if ($q_query->stmt->execute()) {
                $this->pdoFetch->addTime('SQL executed', microtime(true) - $time);
                // $this->setTotal();
                $packages = [];
                // if ($rows = $q_query->stmt->fetchAll(PDO::FETCH_ASSOC)){
                // если запрос будет идти с использование буферизованного запроса
                // $conn->setAttribute(PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false);
                // то ответ всегда будет 0 !!!
                $total = $rows->rowCount(); // если запрос
                // var_export($total); die();
                foreach ($rows as $row) {
                    // print_r($row);
                    // $packages[$row['page']][$row['idx']] = ['id'=>$row['id']];
                    $packages[$row['page']][] = $row['id'];
                }
                // }
                // print_r($rows);die();
                // echo 'XXX';
                // print_r($cache_config);
                $this->pdoFetch->addTime("Получил список пачек, всего:" . count($packages));
                $this->bp->setCache($packages, $cache_config);
            }
        } else {
            // взяли список пакетов из кеша
            $this->pdoFetch->addTime('Взяли список пакетов из кеша', microtime(true) - $time);
        }

        $this->packages = $packages;
        // var_export($total); die();

        if (isset($total)) {
            $this->pdoFetch->addTime('Сохранил Total в свойство result');
            $this->setTotal($total);
        };
    }

    /**
     * Создает и выполняет union sql запрос, с целью получения правильной сортировки
     * данных. Сформированные данные сохраняет в свойство $this->query_chain
     *
     * @return bool
     */
    public function createChainSubQuery()
    {
        $this->pdoFetch->addTime('createChainSubQuery()');

        $cache_config = $this->getCacheConfig('chain_query');

        // $cache_config = array_merge($this->config,['cache_prefix'=>'', 'cache_key' => 'common_chain_ids_'.$this->config['ctx']]);
        // echo ('<pre>');
        // echo (htmlentities(print_r($cache_config,1)));die;

        // var_dump(!($this->config['debug'] && $this->config['cache_disable_in_debug']));
        // echo '<pre>';
        // var_dump($cache_config);
        // var_dump($result = $this->bp->setCache('123', $cache_config));
        // var_dump($result = $this->bp->getCache($cache_config));
        // die();

        if (!($this->config['debug']
            && $this->config['cache_disable_in_debug'])
            && ($result = $this->bp->getCache($cache_config))
        ) {
            $this->query_chain = $result;
            // var_export ($this->query_chain);
            // die();
            $this->pdoFetch->addTime(' - получил данные из кеша');
            return $result;
        }
        $this->pdoFetch->addTime(' - создаю union запрос');

        $union_list = $this->addSubQueryCriteria();
        // var_dump($union_list);
        // die();

        $properties = $this->getProperties();
// print_r($properties);
        // die();

        foreach ($union_list as $union_k => $union_criteria) {
            $q = new BpFilterQuery($this->modx, $this->pdoFetch);
            $q->create($this->config['className']);

            // $q_direct = &$q->get_query();
            // добавляем нумерацию строк в запрос, не получиться
            // т.к. при вызове select() все оборачивается в ``, т.ч. только ручной запрос!
            // $q->select('@rownum:=@rownum+1 rownum, id');
            // $q_direct->query['columns'][] = '@rownum:=@rownum+1 rownum, id';
            // а вот тут from правильно добавляет
            // $q_direct->query['from']['tables'][]= array (
            //     'table' => '(SELECT @rownum := 0)',
            //     'alias' => 'r'
            // );

            $q->select($this->modx->getSelectColumns($this->config['className'], $this->config['className'], '', ['id']));
            // var_dump($union_criteria['tvs']);
            // die();
            $q->joinTvs($union_criteria['tvs'], '', '', false);

            if ($union_criteria['joins']) {
                $this->pdoFetch->addTime('Добавляю JOIN в union_criteria');
                $this->addJoins($q, $union_criteria['joins']);
                // $q->joinCustomTable($union_criteria['joins']);
            }

            // $q->addWhere(array_merge($this->config['where_default'], $union_criteria['where'], $this->config['where']));

            // FIXME вынести в отдельный метод добавление where ???
            $this->pdoFetch->addTime('Добавляю критериии WHERE');
            // $time = microtime(true);

            // сливаем параметры по умолчанию с параметром where
            // if (!empty($this->config['where'])) $this->config['where'] = [];
            // FIXME хранить ли в таком виде $this->config['where'] ???
            // $q->addWhere(array_merge($this->config['where_default'], $union_criteria['where'], $this->config['where']));

            $where = array_merge($this->config['where_default'], $union_criteria['where'], $this->config['where']);
            $where = $this->pdoFetch->additionalConditions($where) ?? null;

            if (!empty($where)) {
                $q->addWhere($where);
            }

            // пройдемся по всем входящим параметрам
            foreach ($properties as $criteria_name => &$value) {
                // тут фильтруем через sinitize
                // $props[$key] = $value;

                if (is_string($value) && trim($value) == '') {
                    continue;
                }
                $this->addQueryCriteria($q, $criteria_name, $value);
            }

            // джоиним с tv шками участвующими в поиске из getScopeTVs()
            $this->pdoFetch->addTime(" - ScopeTVs before");
            if ($tvs = $q->getScopeTVs()) {
                $this->pdoFetch->addTime(" - ScopeTVs exist");

                // в запрос были переданны критерии затрагивающие TV параметры
                // т.к. нам нужно всего лишь построить идентификаторы разбитые по пачкам
                // а не значения TV параметров, то для ускорения выборки, оставим в запросе
                // только используемые TV параметры
                // unset($q_query->query['from']['joins']);
                // $target= & $this->query['from']['joins'];
                // var_dump($q->joinTvById);
                foreach ($tvs as $tv) {
                    $this->pdoFetch->addTime(" - ScopeTVs joinTvById: " . $tv['id']);
                    $q->joinTvById($tv['id'], $tv['name'], @$this->config['tvPrefix'], false);
                    // echo ($q->toSql());
                    // var_dump($z);
                }
                // print_r($q->joinTvById);
            }

            $q->sortBy($union_criteria['order'], '');
            $sql = $q->toSql();
            // echo $sql;die();

            $sql_with_row_number = "SELECT @rownum:=@rownum+1 rownum, result.id, ((@rownum-1) DIV {$this->limit})+1 as page FROM ({$sql}) as `result`, (SELECT @rownum := 0) r";
            // $q_query->query['columns'] = ["filter.idx as idx", "filter.id as id", "(filter.idx DIV {$limit})+1 as page"];

            // echo $sql_with_row_number; die();

            // $result = $this->modx->query($sql_with_row_number);
            // if (is_object($result)) {
            // чуть дольше работает чем fetchAll
            // while ($row_id = $result->fetchColumn()) {
            //   $this->query_chain['ids'][] = $row_id;
            // };
            // $this->query_chain['ids'] = array_merge($this->query_chain['ids'], $result->fetchAll(PDO::FETCH_COLUMN, 0));
            // }

            // $this->pdoFetch->addTime('  - union query: <b>' . $union_k . '</b>');
            // $this->pdoFetch->addTime('  - union query: <b>' . $union_k . '</b>', microtime(true) - $time);
            $this->pdoFetch->addTime('  - union query: ' . $union_k);
            $this->pdoFetch->addTime('  - - SQL: ' . $sql_with_row_number);
            // $this->modx->queryTime += microtime(true) - $time;
            // $this->modx->executedQueries++;

            $this->query_chain['union_queries'][] = "($sql)";
            $this->query_chain['union_queries_with_rowsnum'][] = "($sql_with_row_number)";
            unset($result, $sql_with_row_number, $sql);
        }
        $this->query_chain['union_sql'] = implode(' UNION ', $this->query_chain['union_queries']);
        $this->query_chain['union_sql_with_rowsnum'] = implode(' UNION ', $this->query_chain['union_queries_with_rowsnum']);

        // $this->query_chain['union_sql_with_rowsnum']="
        //   SELECT DISTINCT(res.id) as id FROM ({$this->query_chain['union_sql_with_rowsnum']}) res
        // ";

        // $result = $this->modx->query($this->query_chain['union_sql_with_rowsnum']);
        // if (is_object($result)) {
        //   $this->query_chain['ids'] = $result->fetchAll(PDO::FETCH_COLUMN, 0);
        // }

        // echo ($this->query_chain['union_sql_with_rowsnum']);
        // print_r ($this->query_chain['ids']);
        // die();

        // если мы добавляем rownum (оборачивая в select) после формирования union
        // то на выходе сортировка будет нарушена, тадаааам! так не делаем
        // if ($this->query_chain['union_sql']){
        // $union_sql = "
        //   SELECT result.id, @rownum:=@rownum+1 rownum FROM ({$union_sql}) as result, (SELECT @rownum := 0) r
        //   -- другой вариант той же записи (всего их 3 или 4):
        //   SET @row_number = 0;
        //   SELECT result.id, (@row_number:=@row_number + 1) AS num FROM ({$union_sql}) as result
        // ";
        // LIMIT 10;
        //
        //   $union_sql = "
        //     SELECT result.id id FROM ({$union_sql}) as result
        //       LIMIT 10;
        //   ";
        //
        // }

        //       $c = [
        //   'cache_key'              => 'ids_list',  // ключ передается в основном через ajax
        //   'cache_part'             => 'xxx', // родительская директория в которой храниться кеш, если не указана используется default
        // //   'cache_prefix'           => 'filter', // поддиректория кеша (можно передавать id пользователя, чтобы уменьшить кол-во файлов)
        //   'cache_time'             => 5,
        // ];

        $this->pdoFetch->addTime('  - SQL result: ' . $this->query_chain['union_sql_with_rowsnum']);

        $temp_table_name = $this->getTempFilterTableName($this->requestHash);
        // заполним данные во временную таблицу
        if ($this->prepareTempTable($temp_table_name)) {
            $table = $this->getTableName($temp_table_name);
            // $sql = "INSERT IGNORE
            //   INTO {$table['name_full']} (`idx`,`id`,`page`)
            //   {$this->query_chain['union_sql_with_rowsnum']}";

            // 1-й вариант создаем записи через вложенный select
            // $result = $this->modx->query($sql);

            // 2-й вариант создаем записи через вложенный перечисление, какой быстрее не понятно
            if ($result = $this->modx->query($this->query_chain['union_sql_with_rowsnum'])) {
                // $r=[];
                $r = '';

                foreach ($result as $index => $row) {
                    // var_export($row);
                    // $r[]="({$row['rownum']}, {$row['id']}, {$row['page']})";
                    $r .= "({$row['rownum']}, {$row['id']}, {$row['page']}),";
                    // уменьшаем кол-во импортируемых записей
                    // if ($index === 99) {
                    //     break;
                    // }
                }
                // $r = implode(',', $r);
                $sql = "INSERT IGNORE
                    INTO {$table['name_full']} (`idx`,`id`,`page`) VALUES
                    {$r} (0,0,0);"; // так меньше памяти ест
            }
            // echo $sql;
            // die();

            // $this->pdoFetch->addTime(" - Выборка общих ids в БД");
            $result = $this->modx->exec($sql);
            unset($r);
            // $result = false;

            // echo $sql;
            // echo '==============================</br>';
            // echo $this->query_chain['union_sql_with_rowsnum'];
            // echo '</br>==============================';
            // die();

            // $this->pdoFetch->addTime(" - Выполнил запрос на размещение общих ids в БД\n\r" . $this->query_chain['union_sql_with_rowsnum']);
            $this->pdoFetch->addTime(" - Выполнил запрос на размещение общих ids в БД\n\r" . $sql);

            // var_dump($result);
            if ($result === false) {
                $err = $this->modx->errorInfo();
                if ($err[0] === '00000' || $err[0] === '01000') {
                    // $this->modx->log(\modX::LOG_LEVEL_INFO, "заполнил таблицу: {$table['name_full']}");
                    $this->pdoFetch->addTime(" - заполнил таблицу: {$table['name_full']}");
                } else {
                    // $this->modx->log(\modX::LOG_LEVEL_ERROR, "не удалось заполнить таблицу {$table['name_full']}:" . print_r($err, true));
                    // $this->pdoFetch->addTime(" - ERROR: не удалось заполнить таблицу {$table['name_full']}");
                    throw new \Exception("createChainSubQuery()[ERROR]: не удалось заполнить таблицу {$table['name_full']}");
                }
            } else {
                //   $this->modx->log(\modX::LOG_LEVEL_INFO, "заполнил таблицу: {$table['name_full']}");
                $this->bp->setCache($this->query_chain['union_sql_with_rowsnum'], $cache_config);
                $this->pdoFetch->addTime(' - Разместил информацию в кеше о наличие списка IDS');
            }
        }

        // var_export($this->bp->getCache($c));

        // $q->clear();
        // return !!$this->query_chain['ids'];
        $this->pdoFetch->addTime('END createChainSubQuery()');
        return !!$this->query_chain['union_sql_with_rowsnum'];
    }

    /**
     * Создает таблицу с указанными параметрами
     * @param  string  $name            [description]
     * @param  array   $schema          [description]
     * @param  bool $truncateIfExist [description]
     * @return [type]                   [description]
     */
    private function createTempTable($name = '', $schema = [], $truncateIfExist = false)
    {
        $name = $name ?? $this->getTempFilterTableName($name);
        if (!$name) {
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, 'createTempTable() не указано имя или схема таблицы');
            $this->pdoFetch->addTime("ERROR: createTempTable() не указано имя или схема таблицы");
            return false;
        }
        $table = $this->getTableName($name);

        $sql = "
            CREATE TABLE {$table['name_full']} (
              `idx` INT(10) UNSIGNED NOT NULL AUTO_INCREMENT,
              `id` INT(10) UNSIGNED NOT NULL,
              `page` INT(10) UNSIGNED NULL,
              PRIMARY KEY (`idx`),
              UNIQUE (`id`)
            ) ENGINE = MyISAM;
        ";
        // не знаю какой быстрее ...
        // ) ENGINE = MyISAM;";
        // ) ENGINE = InnoDB;";

        $result = $this->modx->exec($sql);
        // var_dump($result);
        if ($result === false) {
            $err = $this->modx->errorInfo();
            if ($err[0] === '00000' || $err[0] === '01000') {
                $msg = "[WARN] создал таблицу с кодом ошибки {$err[0]}: {$table['name_full']}";
                // $this->modx->log(\modX::LOG_LEVEL_ERROR, $msg);
                $this->pdoFetch->addTime($msg);
                $result = true;
            } elseif ($err[1] == 1050) {
                $msg = "[WARN] таблица {$table['name_full']} существует";
                // $this->modx->log(\modX::LOG_LEVEL_INFO, $msg);
                $this->pdoFetch->addTime($msg);
                $result = true;
            } else {
                $msg = "[ERROR] не удалось создать таблицу {$table['name_full']}:" . print_r($err, true);
                // $this->modx->log(\modX::LOG_LEVEL_ERROR, $msg);
                $this->pdoFetch->addTime($msg);
                $result = false;
            }
        } else {
            $msg = "создал таблицу: {$table['name_full']}";
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, $msg);
            $this->pdoFetch->addTime($msg);
            $result = true;
        }
        return $result;
    }

    /**
     * Возвращает полное имя таблицы в БД с подстановленным префиксом
     *
     * @param string|null $name
     * @return string
     */
    public function prepareTableName(?string $name = ''): string
    {
        if (!$name) {
            throw new \Exception(__METHOD__ . ' [ERROR]: не указан имя таблицы');
        }
        $table__prefix = $this->modx->getOption(\xPDO::OPT_TABLE_PREFIX, null, '');
        $table__name = (strpos($name, $table__prefix) === false) ? $table__prefix . $name : $name;
        return $table__name;
    }

    /**
     * Возвращает список используемых таблиц текущего приложения
     *
     * @return array|null
     */
    public function getAppTables(): ?array
    {
        return $this->getTablesByPrefix($this->temp_table_prefix . '_' . $this->appId . '_');
    }

    /**
     * Производит поиск таблиц в БД по началу их имени
     *
     * @param string $name префикс имени таблицы
     * @return array|null
     */
    private function getTablesByPrefix(string $name = ''): ?array
    {
        if (!$name) {
            throw new \Exception(__METHOD__ . ' [ERROR]: не указано префикс таблиц');
        }

        $result = [];
        $table__name = $this->prepareTableName($name);
        // $table__name_full = $this->modx->escape($this->modx->config['dbname'], '.') . '.' . $this->modx->escape($table__name);

        $sql = "SELECT
                    table_name
                FROM information_schema.tables
                WHERE table_type = 'BASE TABLE'
                    AND table_name LIKE '{$table__name}%'
                    AND table_schema = '{$this->modx->config['dbname']}'
                ORDER BY
                    table_name
                ;";
        $this->pdoFetch->addTime(__METHOD__ . ': ' . $sql);

        $q = $this->modx->query($sql);
        if (is_object($q)) {
            $result = $q->fetchAll(\PDO::FETCH_COLUMN);
        }
        return $result;
    }

    /**
     * Undocumented function
     *
     * @param string|null $name
     * @return array
     */
    public function getTableName(?string $name = ''): array
    {
        $table__name = $this->prepareTableName($name);
        $table__name_full = $this->modx->escape($this->modx->config['dbname'], '.') . '.' . $this->modx->escape($table__name);
        return [
            'name' => $table__name,
            'name_full' => $table__name_full,
        ];
    }

    private function checkExistTable($name = '', $truncateIfExist = false): bool
    {
        // $name = 'modx_access_policy_template_groups';
        if (!$name) {
            throw new \Exception(__METHOD__ . '[ERROR]: не указано имя таблицы');
        }

        $table = $this->getTableName($name);

        // $table__prefix = $this->modx->getOption(xPDO::OPT_TABLE_PREFIX, null, '');
        // $table__name = (strpos($name, $table__prefix) === false) ? $table__prefix . $name : $name;
        // $table__name_full = $this->modx->escape($this->modx->config['dbname'], '.') . '.' . $this->modx->escape($table__name);

        $table__columns_query = "CHECK TABLE {$table['name_full']} QUICK;"; // самый быстрый вариант
        // echo $table__columns_query;
        // $table__columns_query = "SHOW COLUMNS FROM {$table['name_full']};";
        // $table__columns_query = "DESCRIBE {$table['name_full']};";
        $result = $this->modx->query($table__columns_query);
        if (is_object($result)) {
            $table__columns = $result->fetchAll(\PDO::FETCH_ASSOC);
            // print_r($table__columns);die();
            if (!$table__columns || $table__columns[0]['Msg_text'] !== 'OK') {
                // echo('Q [ERROR]: не обнаружена таблица: ' . $table['name_full']);
                // $this->modx->log(\modX::LOG_LEVEL_ERROR, __METHOD__ . '[ERROR]: не обнаружена таблица: ' . $table['name_full']);
                $this->pdoFetch->addTime(__METHOD__ . '[ERROR]: не обнаружена таблица: ' . $table['name_full']);
                $result = false;
            } else {
                $result = true;
                // $result = [
                //   'name' => $table__name,
                //   'name_full' => $table__name_full,
                // ];
                // if ($truncateIfExist) $this->truncateTable($name);
            }
        }
        return $result;
    }

    public function getTempFilterTableName($part = 'default'): string
    {
        // $part = $part ?? $this->temp_table_prefix;
        // MySQL 8.0 max length table name 64 characters
        $table_name = $this->temp_table_prefix . '_' . $this->appId . '_' . $part;
        $table_name = $this->prepareTableName($table_name);
        // var_export($table_name);
        $table_name = substr($table_name, 0, 63);
        $this->pdoFetch->addTime(__METHOD__ . ': ' . $table_name);
        return $table_name;
        // return $result . '_' . $this->config['ctx'] . '_' . $name;
        // $name = $name ?? $this->temp_table_prefix;
        // return $this->temp_table_prefix . $this->config['ctx'];
    }

    /**
     * Подготавливает временную таблицу для размещения данных
     *
     * @param string $name
     * @return boolean
     */
    private function prepareTempTable($name = ''): bool
    {
        // echo $name; die();
        $this->pdoFetch->addTime(__METHOD__ . ' запущен для: ' . $name);
        if (!$name) {
            throw new \Exception('truncateTable()[ERROR]: не указано имя таблицы');
            // $this->pdoFetch->addTime('truncateTable()[ERROR]: не указано имя таблицы');
            // $this->modx->log(\modX::LOG_LEVEL_ERROR, 'truncateTable()[ERROR] не указано имя таблицы');
            // return false;
        }
        if (!$this->checkExistTable($name)) {
            $this->pdoFetch->addTime(__METHOD__ . ' таблица не существует: ' . $name);
            if (!$this->createTempTable($name)) {
                throw new \Exception(__METHOD__ . ' [ERROR]: не смог создать таблицу с именем: ' . $name);
            }
        }
        $table = $this->getTableName($name);

        $result = $this->truncateTable($table['name_full']);

        return $result;
    }

    /**
     * Очищает таблицу
     *
     * @param string $name
     * @return boolean
     */
    private function truncateTable($name = ''): bool
    {
        if (!$name) {
            throw new \Exception(__METHOD__ . ' Параметр "name" - не указан');
        }

        $result = false;
        $table__columns_query = "TRUNCATE TABLE {$name};";
        $q = $this->modx->query($table__columns_query);
        if (!is_object($q)) {
            $this->pdoFetch->addTime(__METHOD__ . ' [ERROR]: произошла ошибка при очистке таблицы ' . $name);
        } else {
            $result = true;
            $this->pdoFetch->addTime(__METHOD__ . ' очистил таблицу ' . $name);
        }
        return $result;
    }

    /**
     * Удаляет таблицы
     *
     * @param string|array $tables
     * @return boolean
     */
    private function dropTables($tables = ''): bool
    {
        if (!$tables) {
            throw new \Exception(__METHOD__ . ' Параметр "tables" - не указан');
        }

        $result = false;
        if (is_string($tables)) {
            $tables = [$tables];
        }
        $tables = implode(', ', $tables);
        $q = "DROP TABLE IF EXISTS {$tables};";
        $result = $this->modx->query($q);
        if (!is_object($result)) {
            // FIXME уведомлять по почте??? может лучше throw???
            $this->pdoFetch->addTime(__METHOD__ . ' [ERROR]: произошла ошибка при удалении таблицы ' . $tables);
        } else {
            $this->pdoFetch->addTime(__METHOD__ . ' удалил таблицы: ' . $tables);
            $result = true;
        }
        return $result;
    }

    public function getLimitVal(?int $limit): int
    {
        $limit = intval($limit ?? $this->config['limit']);
        return in_array((int) $limit, $this->allowLimit) ? (int) $limit : $this->allowLimit[0];
    }

    /**
     * Устанаваливает границы запроса
     * @param array $data критерии содержащий offset и limit
     */
    public function setLimit(): void
    {
        // $limit = $this->getLimitVal($limit);
        // $this->query->setLimit(@$this->offset, $limit, false);
        $this->query->setLimit(@$this->offset, $this->limit, false);
        $this->pdoFetch->addTime("setLimit: {$this->offset} - {$this->limit}");
    }

    /**
     * Устанавливает плейсхолдер total для пагинации используя в качестве имени
     * значение поля totalVar свойства $this->config
     * @param array $props [description]
     */
    public function setTotal(?int $total = null): bool
    {
        if (!$this->config['setTotal'] || in_array($this->config['return'], ['sql', 'ids'])) {
            return false;
        }

        if (!is_null($total)) {
            $time = microtime(true);

            $q = clone $this->query->get_query();
            $tmp_first_select = $q->query['columns'][0];

            unset($q->query['columns']);
            $q->query['columns'][0] = $tmp_first_select;
            $q->prepare();
            $sql = $q->toSQL();

            // print_r($q->query);
            // die();
            // var_export($query->query['from']);
            // попробовать очистить запрос от мусора???
            $q_counter = "SELECT COUNT(main.{$this->query->get_pk()}) c FROM ($sql) main;";

            $this->pdoFetch->addTime('Запрос на TOTAL: ' . $q_counter, microtime(true) - $time);
            $time = microtime(true);

            $total = 0;

            if ($result = $this->modx->query($q_counter)) {
                $total = $result->fetch(\PDO::FETCH_COLUMN);
                // $total = $result->fetchAll(PDO::FETCH_ASSOC);
                // вариант подсчета строк выполняется в 2-4 раза дольше
                // $total = count($result->fetchAll(PDO::FETCH_COLUMN));
                $this->pdoFetch->addTime('выполнил запрос подсчета строк TOTAL: ' . $total, microtime(true) - $time);
            }
        } else {
            $total = (int) $total;
        }

        $this->result['total'] = $total;
        $cache_config = $this->getCacheConfig('total');

        $this->bp->setCache($this->result['total'], $cache_config);
        $this->pdoFetch->addTime('сохранил данные TOTAL в кеш');
        $this->modx->setPlaceholder($this->config['totalVar'], $this->result['total']);

        return true;
    }

    /**
     * Возвращает кол-во документов
     *
     * @param Array $props
     * @return int|bool
     */
    public function getTotal()
    {
        // $total = 0;
        if (!isset($this->result['total'])) {
            $cache_config = $this->getCacheConfig('total');
            $total = $this->bp->getCache($cache_config);
            if (!isset($total)) {
                return false;
            }
            $this->pdoFetch->addTime('взял данные TOTAL из кеша');
            $this->pdoFetch->addTime('Total rows: ' . $total);
        } else {
            $total = $this->result['total'];
        }

        $total = (int) $total;

        $this->modx->setPlaceholder($this->config['totalVar'], $total);
        return $total;
    }
}
