<?php

declare (strict_types = 1);

namespace BpFilter\Filter;

use BpFilter;
use BpFilter\Handler\Query\QueryBuilder as BpFilterQuery;

final class Users extends AbstractFilter implements CriteriaInterface
{

    const ALLOWED_PROPERTIES = [
        'id',
        'name',
        'username',
        // 'family',
        // 'firstname',
        // 'patronymic',
        'email',
        'resId',
        'have_post',
        'have_paid_order',
        'createdfrom',
        'createdto',
        'sortby',
        'sortdir',
        // 'page', ???
        // 'limit', // лучше убрать меньше соблазна будет
        // 'cache_key',
    ];

    public $config = [
        'tpl' => '@FILE:ifilter/row_project.tpl', //@CHUNK: orderServicesItem'
        'tplWrap' => '@INLINE:{$output}',

        /* PDO properties*/
        // toSeparatePlaceholders
        // outputSeparator
        // fastMode
        'limit' => 15,
        // // 'limit_default'            => 15, // влияет на заполнение tmp table: filter
        'className' => 'modUser',
        'useWeblinkUrl' => false,
        'showLog' => false, // срабатывает только для pdoFetch, при true данные попадают в мой log
        'setTotal' => true,
        'totalVar' => 'total',
        'return' => 'chunks', // chunks, data, sql or ids
        'where' => [],
        'includeContent' => 0,

        'select' => [
            "modUser" => "id, username",
            "modUserProfile" => "family,firstname,patronymic,phone,email,blocked,company,photo,status,created,thislogin,logincount,email_confirm",
            "modResource" => "`modResource`.`id` AS `has_resource`",
            "Orders" => "`Orders`.`id` AS `has_order`",
            // "Orders" => "`modResource`.`id` AS `has_resource`",
            // "modResource" => "IFNULL(`modResource`.`id`, '0') AS `has_resource`",
            // "IFNULL(`modResource`.`id`, '0') AS `has_resource`",
            // "modUserProfile" => "*",
        ],

        'where' => [],
        'where_default' => [
            'active:=' => 1,
            //   'deleted:='   => 0,
            // 'modResource.context_key:=' => $this->config['ctx'],
        ],

        'leftJoin' => [
            "modUserProfile" => [
                "class" => "modUserProfile"
                , "alias" => "modUserProfile"
                , "on" => "modUserProfile.internalKey = modUser.id",
            ]
        ],

        'finalJoins' => [
            'leftJoin' => [
                "modResource" => [
                    "class" => "site_content"
                    , "alias" => "modResource"
                    , "on" => "modResource.id"
                    , "onSelect" => [
                        'className' => 'modResource',
                        "alias" => "user_resources",
                        'select' => 'id',
                        // FIXME не сработает нужно исправлять на addSelect
                        // [
                        //     "modResource" => ['id'],
                        // ],

                        'where' => [
                            'user_resources.createdby = modUser.id',
                        ],

                        'limit' => 1,
                    ],
                ],
                "Orders" => [
                      "class"    => "orders"
                    , "alias"    => "Orders"
                    , "on"       => "Orders.id"
                    , "onSelect" => [
                        'className' => 'Order',
                        "alias"     => "user_orders",
                        'select'    => 'id',
                        'where'     => [
                            'user_orders.authorId = modUser.id',
                            'user_orders.paymentStatus = 1',
                        ],
                        'limit' => 1,
                    ],
                ],
            ],
        ],

        'tvPrefix' => 'TV',
        'includeTVs' => '',
        // 'where'                    => '{"template":"6"}',

        'saveStat' => false,

    ];

    /**
     * {@inheritDoc}
     */
    public function __construct(...$args)
    {
        return parent::__construct(...$args);
    }

    /**
     * {@inheritDoc}
     */
    public function beforeProcess(): void
    {
        $orders = $this->modx->getService('orders', 'Orders', $this->modx->getOption('orders.core_path', null, $this->modx->getOption('core_path') . 'components/orders/') . 'model/orders/');
    }

    /**
     * {@inheritDoc}
     */
    public function getAppId(): string
    {
        return $this->mainAppId;
    }

    /**
     * {@inheritDoc}
     */
    public function addSortQueryCriteria(BpFilterQuery &$query, string $temp_table_name, array $properties = []): void
    {
        // FIXME непонятно может перетащить в другое место ...
        $sortDir = (!$properties['sortdir'] || strtolower($properties['sortdir']) == 'asc') ? 'ASC' : 'DESC';

        if (!$properties['sortby'] || strtolower($properties['sortby']) == 'id') {
            // if ($ids){
            // если мы не используем $query->addWhere(array('id:IN' => $query_chain['ids']));
            // для ограничения выборки, то можем использовать offset и limit для
            // выборки внутри массива
            // $ids = array_slice($query_chain['ids'], 0, 10000);
            // print_r($ids);die();

            // используем вариант выборки по временной таблице
            $query->joinCustomTable([
                'name'         => $temp_table_name,
                'alias'        => 'filter',
                'alias_prefix' => '',
                'addSelect'    => false,
                'type'         => 'innerJoin',
                'conditions'   => ['sql' => "{$this->config['className']}.id = filter.id"],
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
            // $query->sortBy('TVcost', $sortDir);
        }
    }

    /**
     * {@inheritDoc}
     */
    public function addQueryCriteria(BpFilterQuery &$query, string $criteria_name, $criteria_value)
    {
        switch ($criteria_name) {
            case 'id':
                $criteria_value = (int) $criteria_value;
                if ($criteria_value) {
                    $query->addWhere([
                        ($criteria_name . ':=') => $criteria_value,
                    ]);
                }
                break;

            case 'name':
                $criteria_value = trim($criteria_value, '%');
                if ($criteria_value) {
                    $query->addWhere([
                        ('modUserProfile.family:LIKE') => "%{$criteria_value}%",
                        ('OR:modUserProfile.firstname:LIKE') => "%{$criteria_value}%",
                        ('OR:modUserProfile.patronymic:LIKE') => "%{$criteria_value}%",
                        ('OR:modUserProfile.fullname:LIKE') => "%{$criteria_value}%",
                    ]);
                }
                break;

            case 'username':
                $criteria_value = trim($criteria_value, '%');
                if ($criteria_value) {
                    $query->addWhere([
                        ('modUser.' . $criteria_name . ':LIKE') => "%{$criteria_value}%",
                    ]);
                }
                break;

            case 'createdfrom':
                if ($criteria_value) {
                    $query->addWhere([
                        ('modUser.created:>=') => "{$criteria_value} 00:00:00",
                        // ('modUser.' . $criteria_name . ':>=') => "{$criteria_value} 00:00:00",
                    ]);
                }
                break;

            case 'createdto':
                if ($criteria_value) {
                    $query->addWhere([
                        ('modUser.created:<=') => "{$criteria_value} 23:59:59",
                        // ('modUser.' . $criteria_name . ':<=') => "{$criteria_value} 23:59:59",
                    ]);
                }
                break;

            case 'email':
                $criteria_value = trim($criteria_value, '%');
                if ($criteria_value) {
                    $query->addWhere([
                        ('modUserProfile.' . $criteria_name . ':LIKE') => "%{$criteria_value}%",
                    ]);
                }
                break;

            case 'have_post':
                if (!!$criteria_value) {
                    // $this->addJoins($query, $this->config['finalJoins']);
                    // $query->joinCustomTable($this->config['finalJoins']['leftJoin']['modResource']);
                    $query->joinCustomTable([
                        'name'      => 'site_content',
                        'alias'     => 'user_resources',
                        'type'      => 'innerJoin',
                        'addSelect' => false,
                        'onCustom'  => 'user_resources.createdby = modUser.id',
                    ]);
                }
                break;

            case 'have_paid_order':
                if (!!$criteria_value) {
                    $query->joinCustomTable([
                        'name'      => 'orders',
                        'alias'     => 'Orders',
                        'type'      => 'innerJoin',
                        'addSelect' => false,
                        'onCustom'  => 'Orders.authorId = modUser.id and Orders.paymentStatus = 1',
                    ]);
                }
                break;

            // case 'sortby':
            // case 'sortdir':
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
        return [

            //  все остальные, но документы текущего контекста в приоритете
            'Все (по умолчанию)' => [
                'tvs' => [
                    // 'topNine' => ['topNine', '', '', false, 'leftJoin'],
                    // 'unPubTop' => ['unPubTop', '', '', false, 'leftJoin'],
                    // 'international' => ['international', '', '', false, 'leftJoin']
                ],
                'where' => [
                    //   "modUser.deleted:=" => 0
                    // "(`TVtopNine`.`value` != '1' OR `TVtopNine`.`value` IS NULL)",
                    // '(TVinternational.value != "Y" OR TVinternational.value IS NULL)',
                    // "(`TVtopNine`.`value` != '1' OR (`TVunPubTop`.`value` < NOW() OR `TVunPubTop`.`value` IS NULL  OR `TVunPubTop`.`value` = ''))",
                    // 'TVinternational.value:!=' => 'Y',
                    // "(`TVtopNine`.`value` != '1' OR (`TVunPubTop`.`value` < NOW() OR `TVunPubTop`.`value` IS NULL  OR `TVunPubTop`.`value` = ''))",
                ],
                //   'order' => "FIELD(`modResource`.`context_key`, '{$this->config['ctx']}') DESC, `modResource`.`publishedon` DESC",
                'order' => "`modUser`.`id` DESC",
                // FIXME может просто копировать из текущего конфига??? Тогда текущие присоединения в конфиге нужно выносить в отдельный параметр joins
                'joins' => [
                    'leftJoin' => [
                        "modUserProfile" => [
                            "class" => "modUserProfile"
                            , "alias" => "modUserProfile"
                            , "on" => "modUserProfile.internalKey = modUser.id",
                        ]
                    ],
                ],
            ],
        ];
    }

    /**
     * {@inheritDoc}
     */
    public function getOffset(array &$data = []): int
    {
        return parent::getOffset($data);
    }

    /**
     * {@inheritDoc}
     */
    public function getLimit(array &$data = []): int
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
