<?php

/**
 * Пример задания крон по очистке кеша основываясь на данных регистра
 *
 * Используется в комбинации с компонентом BP. Так как это кусок кода
 * для обеспечения его работоспособности необходимо внести соответствующие изменения
 *
 * @author Prishepenko Stepan: Setest <itman116@gmail.com>
 * @version 0.1.0
 * @created 2019-12-25T
 * @since 2019-12-25
 */


// ....

$args = $args ?: $this->properties;
$default = [
    'appId' => 'projects',
    'context' => $this->config['ctx'],
    'showLog' => 1,
    'debug' => 1,
];
$scriptProperties = \array_merge($default, $args);
$this->modx->switchContext($scriptProperties['context']);

$path = MODX_CORE_PATH . '/components/bp/elements/apps/filter/';

require_once $path . '/vendor/autoload.php';
$this->log($scriptProperties);

$send_email = true;
try {
    $filter = BpFilter\FilterFactory::getFilter($scriptProperties['appId'], $this->modx);
    $filter->initialize($this->config['ctx'], $scriptProperties);

    // получим весь кеш который требуется удалить
    $result = $filter->getRegisterClearCache(true, true);
    if (!empty($result)) {
        $appIds = [];
        foreach ($result as $app) {
            $appIds[] = array_keys($app)[0];
        }
        $appIds = array_unique($appIds);
        $this->log('Сбрасываю по данным регистра: ' . print_r($appIds, true));

        foreach ($appIds as $appId) {
            $filter->removeCache($appId);
        }

        $this->log($filter->pdoFetch->getTime());
    } else {
        $send_email = false;
    }

} catch (\Exception $e) {
    $msg = 'throw error: ' . $e->getMessage();
    $this->log_error($msg);

    if (!empty($filter->pdoFetch)) {
        $this->log($filter->pdoFetch->getTime());
    }
}

if ($send_email) {
    $msg = nl2br($this->getLog(), false);
    $this->bp->sendEmail($this->config['email_to'], '[INFO] BP cron: clearCocaineCache', $msg, '', [], '', false);
}
