<?php
/**
 * Плагин демонстрирующий размещение данных в кеше и их удаление
 *
 * @author Prishepenko Stepan: Setest <itman116@gmail.com>
 * @version 0.1.0
 * @created 2019-12-25T
 * @since 2019-12-25
 */

/** @var modX $modx */
switch ($modx->event->name) {
    case 'OnDocPublished':
    case 'OnDocUnPublished':
        $modx->log(modX::LOG_LEVEL_ERROR, '[BP cocain] pos 1');

        $template = $resource->get('template');

        $working_templates = [
            6 => 'projects',
            30 => 'business',
        ];

        if (isset($working_templates[$template])) {
            $modx->log(modX::LOG_LEVEL_ERROR, '[BP cocain] pos 1');

            $appId = $working_templates[$template];

            $path = MODX_CORE_PATH . '/components/bp/elements/apps/filterDev/';
            require_once $path . '/vendor/autoload.php';
            $scriptProperties = [
                'showLog' => 0,
                'appId' => $appId,
                // 'debug' => 1,
                // 'saveStat' => false,
                // 'cache_disable_in_debug' => 1,
            ];

            // добавляем задание на сброс кеша
            try {
                $filter = BpFilter\FilterFactory::getFilter($scriptProperties['appId'], $modx);
                $filter->initialize($modx->context->key, $scriptProperties);
                $filter->setRegisterClearCache($appId . '_' . $resource->get('context_key'));
                $modx->log(modX::LOG_LEVEL_ERROR, '[BP cocain] pos 3');
            } catch (\Exception $e) {
                $modx->log(modX::LOG_LEVEL_ERROR, '[BP cocain] pos 4');
                if (!empty($filter->pdoFetch)) {
                    if ($modx->user->hasSessionContext('mgr') && !empty($filter->config['showLog'])) {
                        $result = '<pre class="pdoResourcesLog col-md-12">' . (var_export($filter->pdoFetch->getTime(), true)) . '</pre>' . $result;
                        // $modx->event->returnedValues['log'] = $result;
                    }
                }
            }
        }
        break;

    case 'bpCocainRemoveCache':
        $modx->event->_output = null;
        if (!$appId) {
            // $modx->event->returnedValues = ['data'=>'xxx'];
            $modx->event->output('Не указан appId');
            return;
        }

        if ($bp = $modx->getService('bp')) {
            if ($_POST['bp_action'] == 'users/remove_cache') {
                $path = MODX_CORE_PATH . '/components/bp/elements/apps/filterDev/';
                require_once $path . '/vendor/autoload.php';
                $scriptProperties = [
                    'showLog' => 0,
                    'appId' => $appId,
                    // 'debug' => 1,
                    // 'saveStat' => false,
                    // 'cache_disable_in_debug' => 1,
                ];

                try {
                    $filter = BpFilter\FilterFactory::getFilter($scriptProperties['appId'], $modx);
                    $filter->initialize($modx->context->key, $scriptProperties);

                    $filter->bp->unsetSessionStore($scriptProperties['appId']);
                    if (!$filter->removeCache()) {
                        $modx->event->output('Не удалось сбросить кеш для: ' . $appId);
                    }

                    // if ($modx->user->hasSessionContext('mgr') && !empty($filter->config['showLog'])) {
                    //     $result = '<pre class="pdoResourcesLog col-md-12">' . (var_export($filter->pdoFetch->getTime(), true)) . '</pre>' . $result;
                    // }
                } catch (\Exception $e) {
                    // $msg = '[BP - ifilter] throw error: ' . $e->getMessage();

                    if (!empty($filter->pdoFetch)) {
                        if ($modx->user->hasSessionContext('mgr') && !empty($filter->config['showLog'])) {
                            $result = '<pre class="pdoResourcesLog col-md-12">' . (var_export($filter->pdoFetch->getTime(), true)) . '</pre>' . $result;
                            $modx->event->returnedValues['log'] = $result;
                        }
                    // } else {
                        // $modx->log(\modX::LOG_LEVEL_ERROR, $msg);
                    }
                }
            }
            @session_write_close();
            // return $result;
        }
        break;
}
