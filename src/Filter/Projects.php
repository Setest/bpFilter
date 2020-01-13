<?php

declare(strict_types=1);

namespace BpFilter\Filter;

use BpFilter\Handler\Query\QueryBuilder as BpFilterQuery;

final class Projects extends AbstractFilter implements CriteriaInterface
{
    use ModResourceTrait;

    /**
     * Идентификатор приложения - определяется автоматически на основе имени класса
     *
     * Уникальный идентификатор приложения (сниппета), для формирования ID кеша.
     * Его имя должно соответствовать начала имени файла.
     * FIXME: может быть перегружен через параметры сниппета ??? или не может...
     * может например внешний вид при выводе проектов на главной и на странице проектов...
     *
     * @var string
     */
    // public $appId = 'projects';

    const ALLOWED_PROPERTIES = [
        // 'parent',
        'categories',
        'projectCountry',
        'country',
        'projectRegion',
        'region',
        'projectCity',
        'city',
        'stage',
        'pagetitle',
        'id',
        'cost_from',
        'cost_to',
        'international',
        'patent',
        'sortby',
        'sortdir',
        // 'page', ???
        // 'limit', // лучше убрать меньше соблазна будет
        // 'cache_key',
    ];

    /**
     * {@inheritDoc}
     */
    public function __construct(...$args)
    {
        // echo 'Projects';
        // var_export(self::ALLOWED_PROPERTIES);
        // die();
        return parent::__construct(...$args);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeProcess(): void
    {
    }

    /**
     * {@inheritDoc}
     */
    public function getAppId(...$args): string
    {
        return parent::getAppId(...$args);
    }

    /**
     * {@inheritDoc}
     */
    public function addSortQueryCriteria(BpFilterQuery &$query, string $temp_table_name, array $properties = []): void
    {
        // FIXME непонятно может перетащить в другое место ...
        $sortDir = (!$properties['sortdir'] || strtolower($properties['sortdir']) == 'asc') ? 'ASC' : 'DESC';

        if (!$properties['sortby'] || strtolower($properties['sortby']) == 'publishedon') {
            // if ($ids){
            // если мы не используем $query->addWhere(array('id:IN' => $query_chain['ids']));
            // для ограничения выборки, то можем использовать offset и limit для
            // выборки внутри массива
            // $ids = array_slice($query_chain['ids'], 0, 10000);
            // print_r($ids);die();

            // используем вариант выборки по временной таблице

            $query->joinCustomTable([
                'name' => $temp_table_name,
                'alias' => 'filter',
                'alias_prefix' => '',
                'addSelect' => false,
                'type' => 'innerJoin',
                'conditions' => ['sql' => "{$this->config['className']}.id = filter.id"],
            ]);

            // echo $temp_table_name;
            // echo ($this->pdoFetch->getTime());
            // die();

            // $query->sortBy('', "FIELD({$this->class}.id, " . implode(',', $ids) . ") {$sortDir}");
            $query->sortBy('', "filter.idx {$sortDir}");

            // echo $query_chain['union_sql_with_rowsnum'];
            // echo $sql;
            // die();
            // }
        } else {
            $query->sortBy('TVcost', $sortDir);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addQueryCriteria(BpFilterQuery &$query, string $criteria_name, $criteria_value)
    {
        switch ($criteria_name) {
            case 'parent':
                $criteria_value = (int) $criteria_value;
                if ($criteria_value) {
                    $query->addWhere(array(
                        'parent:=' => $criteria_value,
                    ));
                }
                break;

            case 'categories':
                // case 'category':
                if ($tv = $this->getTvDataByName($criteria_name)) {
                    if (is_array($criteria_value)) {
                        $query->addScopeTVs($tv);
                        $i = 0;
                        foreach ($criteria_value as $tmp) {
                            $prefix = ($i) ? 'OR:' : '';
                            $query->addWhere(array(
                                "{$prefix}TV{$tv['id']}.value:LIKE" => "%:{$tmp}:%",
                            ));
                            $i++;
                            // "{$prefix}{$tvNameCriteria}:LIKE" => "%:{$tmp}:%",
                            // "TV{$tv['id']}.value:=" => $tmp,
                            // ), xPDOQuery::SQL_OR);
                        }
                    }
                }
                break;

            case 'projectCountry':
            case 'country':
            case 'projectRegion':
            case 'region':
            case 'projectCity':
            case 'city':
            case 'stage':
                if (is_array($criteria_value)) {
                    if (in_array($criteria_name, ['country', 'region', 'city'])) {
                        $tvName = 'project' . ucfirst(strtolower($criteria_name));
                    }

                    if ($tv = $this->getTvDataByName($tvName)) {
                        $query->addScopeTVs($tv);
                        // добавить OR
                        $tmp_q = [];
                        foreach ($criteria_value as $tmp) {
                            $tmp_q[] = ["TV{$tv['id']}.value:LIKE" => "%{$tmp}%"];
                            // $tvs_list_result[$tvNameCriteria];
                            // $tmp_q[] = ["{$tvNameCriteria}.value:LIKE" => "%{$tmp}%"];
                            // $query->addWhere(array(
                            // "{$tvNameCriteria}.value:LIKE:OR" => "%{$tmp}%",
                        }
                        $query->addWhere($tmp_q, \xPDOQuery::SQL_OR);
                    }
                }
                break;

            case 'pagetitle':
                if (is_numeric($criteria_value)) {
                    $query->addWhere(array(
                        "id:=" => (int) $criteria_value,
                    ));
                } else {
                    $query->addWhere(array(
                        "pagetitle:LIKE" => "%{$criteria_value}%",
                    ));
                }
                break;

            case 'cost_from':
            case 'cost_to':
                // $tvName = 'cost';
                if (isset($criteria_value) && $tv = $this->getTvDataByName('cost')) {
                    $query->addScopeTVs($tv);
                    $criteria_value = (int) $criteria_value;
                    $tmp_condition = ($criteria_name == 'cost_from') ? '>=' : '<=';
                    $query->addWhere(array(
                        "`TV{$tv['id']}`.`value` {$tmp_condition} {$criteria_value}",
                        // "TVcost.value:{$tmp_condition}" => (int)$criteria_value,
                        // "TVcost {$tmp_condition} " . intval($criteria_value),
                        // "`TV{$tv['id']}`.`value` {$tmp_condition} " . intval($criteria_value),
                    ));
                }
                break;

            case 'international':
                if (!empty($criteria_value) && $tv = $this->getTvDataByName('international')) {
                    $query->addScopeTVs($tv);
                    $query->addWhere(array(
                        "TV{$tv['id']}.value:=" => 'Y',
                    ));
                }
                break;

            case 'patent':
                if (!empty($criteria_value) && $tv = $this->getTvDataByName('patent')) {
                    $query->addScopeTVs($tv);
                    $query->addWhere(array(
                        "TV{$tv['id']}.value:=" => 'Да',
                    ));
                }
                break;

              // оставляем пустыми иначе удалит поля
            // case 'sortby':
            // case 'sortdir':
            //     //   $sortdir = (@$data['sortdir'] && strtoupper($data['sortdir']) == 'ASC') ? 'ASC' : 'DESC';
            //     //   $query->sortBy("{$this->class}.{$criteria_value}", $sortdir);
            //     break;

            // // case 'offset':
            // case 'page':
            // case 'limit':
            //     // $criteria_value = (int)$criteria_value;
            //     break;

            // // список оставшихся разрешенных опций для передачи
            // // case 'q':
            // case 'cache_key':
            //     break;

            default:
                // если у нас нет критериев для такого параметра удаляем его, т.к. используем в
                // качестве параметров кеширования
                // unset($props[$key]);
                break;
        }
        return;
    }

    /**
     * {@inheritDoc}
     */
    public function addSubQueryCriteria(): array
    {
        $check_in_context = ($this->config['ctx'] != 'web') ? ['TVin_context.value:=' => $this->config['ctx']] : '';
        return [
            // только TOP9
            'ТОП 9' => [
                'tvs' => [
                    'topNine' => ['topNine', '', '', false, 'leftJoin'],
                    'unPubTop' => ['unPubTop', '', '', false, 'leftJoin'],
                    // 'in_context' => ['in_context', '', '', false, 'leftJoin'],
                    // 'auto_translate' => ['auto_translate', '', '', false, 'leftJoin'],
                ],
                'where' => [
                    'TVtopNine.value:=' => 1,
                    // 'TVunPubTop.value >= NOW()',
                    // $check_in_context,
                    // 'TVin_context.value:=' => $this->config['ctx'],
                    // "(`TVauto_translate`.`value` != '1' OR `TVauto_translate`.`value` IS NULL)",
                    'modResource.context_key:=' => $this->config['ctx'],
                    // '(TVunPubTop.value BETWEEN (NOW() - INTERVAL 2 WEEK) AND NOW())',
                    // AND `modx_users`.`created` BETWEEN (NOW() - INTERVAL 2 MONTH) AND NOW()
                    // AND `modx_users`.`created` BETWEEN STR_TO_DATE('2008-08-14 00:00:00', '%Y-%m-%d %H:%i:%s')
                    // AND STR_TO_DATE('2008-08-23 23:59:59', '%Y-%m-%d %H:%i:%s');
                ],
                'order' => "CAST(`TVunPubTop`.`value` AS DATETIME) DESC",
                // 'order' => "TVunPubTop.value DESC",
            ],

            // все остальные созданные в текущем контексте, для текущего контекста
            'Только для текущего контекста' => [
              'tvs' => [
                // 'topNine' => ['topNine', '', '', false, 'leftJoin'],
                // 'unPubTop' => ['unPubTop', '', '', false, 'leftJoin'],
                'in_context' => ['in_context', '', '', false, 'leftJoin'],
                // 'international' => ['international', '', '', false, 'leftJoin'],
              ],
              'where' => [
                // [
                  // "(`TVtopNine`.`value` != '1' OR `TVtopNine`.`value` IS NULL OR (`TVunPubTop`.`value` < NOW() OR `TVunPubTop`.`value` IS NULL  OR `TVunPubTop`.`value` = ''))",
                  // "(`TVtopNine`.`value` != '1' OR `TVtopNine`.`value` IS NULL)",
                //   '(TVinternational.value != "Y" OR TVinternational.value IS NULL)',
                // ],
                "(`TVin_context`.`value` = '{$this->config['ctx']}' OR `TVin_context`.`value` IS NULL)",
                // 'TVin_context.value:=' => $this->config['ctx'],
                'modResource.context_key:=' => $this->config['ctx'],
                // $check_in_context,
              ],
              'order' => "modResource.publishedon DESC",
            ],

            // только международные
            // 'Только международные' => [
            //   'tvs' => [
            //     // 'topNine' => ['topNine', '', '', false, 'leftJoin'],
            //     // 'unPubTop' => ['unPubTop', '', '', false, 'leftJoin'],
            //     'international' => ['international', '', '', false, 'leftJoin'],
            //   ],
            //   'where' => [
            //     // 'TVtopNine.value:!=' => 1,
            //     // "(`TVtopNine`.`value` != '1' OR (`TVunPubTop`.`value` < NOW() OR `TVunPubTop`.`value` IS NULL  OR `TVunPubTop`.`value` = ''))",
            //     'TVinternational.value:=' => 'Y',
            //     'modResource.context_key:=' => $this->config['ctx'],
            //   ],
            //   'order' => "modResource.publishedon DESC",
            // ],

            // все остальные, но документы текущего контекста в приоритете
            'Остальные (переведенные)' => [
              'tvs' => [
                // 'topNine' => ['topNine', '', '', false, 'leftJoin'],
                // 'unPubTop' => ['unPubTop', '', '', false, 'leftJoin'],
                // 'international' => ['international', '', '', false, 'leftJoin']
              ],
              'where' => [
                // "(`TVtopNine`.`value` != '1' OR `TVtopNine`.`value` IS NULL)",
                // '(TVinternational.value != "Y" OR TVinternational.value IS NULL)',
                // "(`TVtopNine`.`value` != '1' OR (`TVunPubTop`.`value` < NOW() OR `TVunPubTop`.`value` IS NULL  OR `TVunPubTop`.`value` = ''))",
                // 'TVinternational.value:!=' => 'Y',
                // "(`TVtopNine`.`value` != '1' OR (`TVunPubTop`.`value` < NOW() OR `TVunPubTop`.`value` IS NULL  OR `TVunPubTop`.`value` = ''))",
              ],
              'order' => "FIELD(`modResource`.`context_key`, '{$this->config['ctx']}') DESC, `modResource`.`publishedon` DESC",
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getOffset(array &$data = []) : int
    {
        return parent::getOffset($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getLimit(array &$data = []) : int
    {
        // $limit = 0;
        // if ($data['limit']) {
        //     $limit = $data['limit'];
        // }
        unset($data['limit']);
        $limit = $this->getLimitVal(null);
        return $limit;
    }
}
