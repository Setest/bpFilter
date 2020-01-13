<?php
/**
 * Сниппет ускоряющий вывод ресурсов за счет кеширования результатов обращений к БД
 * и кеширования результатов постранично
 *
 * Используется в комбинации с компонентом BP
 *
 * @author Prishepenko Stepan: Setest <itman116@gmail.com>
 * @version 0.1.0
 * @created 2019-12-25T
 * @since 2019-12-25
 */

$path = '.';
if (defined('MODX_CORE_PATH')) {
    $path = MODX_CORE_PATH . '/components/bp/elements/apps/filter/';
}

use BpFilter\FilterFactory;

require_once $path . '/vendor/autoload.php';

if (PHP_SAPI === 'cli') {
    define('MODX_API_MODE', true);

    // иначе скажет что прав нет
    if (!$_GET || !$_GET['ctx']) {
        $_GET['ctx'] = 'web';
    }

    // relative path to base of modx project
    $base_path = dirname(dirname(dirname(dirname(dirname(dirname(dirname(__FILE__)))))));

    $path = $base_path . '/config.core.php';
    if (file_exists($path)) {
        require_once "{$path}";
    } else {
        echo "Не могу найти путь: {$path}";
        exit();
    }

    require_once MODX_CORE_PATH . 'config/' . MODX_CONFIG_KEY . '.inc.php';
    require_once MODX_CONNECTORS_PATH . 'index.php';

    error_reporting(E_ALL);
    ini_set('display_errors', '1');

    $modx->getService('error', 'error.modError');
    $modx->setLogLevel(\modX::LOG_LEVEL_INFO);
    // $modx->setLogLevel(\modX::LOG_LEVEL_ERROR);
    // $modx->setLogTarget('FILE');
    $modx->setLogTarget('ECHO');
    $modx->error->message = null;

    parse_str(implode('&', array_slice($argv, 1)), $_GET);

    $scriptProperties = $scriptProperties ?? \array_merge($_GET, [
        'context' => 'web',
        'showLog' => 1,
        'debug' => 1,
        'appId' => 'projects',
    ]);
    $modx->switchContext($scriptProperties['context']);
}

// FIXME отключить в продакшене
// $modx->getService('error', 'error.modError');
// $modx->setLogLevel(\modX::LOG_LEVEL_INFO);
// // $modx->setLogLevel(\modX::LOG_LEVEL_ERROR);
// // $modx->setLogTarget('FILE');
// $modx->setLogTarget('HTML');
// $modx->error->message = null;

$result = '';
// \var_export($scriptProperties);
// die();

try {
    $filter = BpFilter\FilterFactory::getFilter($scriptProperties['appId'], $modx);
    $filter->initialize($modx->context->key, $scriptProperties);
    $result = $filter->process();
    // if ($modx->user->hasSessionContext('mgr') && !empty($filter->config['showLog'])) {
    //     $result = '<pre class="pdoResourcesLog col-md-12">' . (var_export($filter->pdoFetch->getTime(), true)). '</pre>' . $result;
    // }
} catch (\Exception $e) {
    $msg = '[BP filter] throw error: ' . $e->getMessage();

    if (!empty($filter->pdoFetch)) {
        // echo 123; die();
        if ($modx->user->hasSessionContext('mgr') && !empty($filter->config['showLog'])) {
            // $result = '<pre class="pdoResourcesLog col-md-12">' . htmlspecialchars(var_export($filter->pdoFetch->getTime(), true)). '</pre>' . $result;
            $filter->pdoFetch->addTime($msg);
            $result = '<pre class="pdoResourcesLog col-md-12">' . (var_export($filter->pdoFetch->getTime(), true)) . '</pre>' . $result;
        }
    } else {
        $modx->log(\modX::LOG_LEVEL_ERROR, $msg);
    }
}

if (XPDO_CLI_MODE) {
    echo $result;
}
return $result;