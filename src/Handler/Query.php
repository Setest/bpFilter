<?php

namespace BpFilter\Handler\Query;

/**
 * Обработчик облегчающий работу с xPDO (modX)
 *
 * Упрощающий построение сложных запросов к БД с использованием TV параметров
 * кастомных таблиц отсуствующих в карте (схеме) modx, различных присоединений
 * и извращений с where
 *
 * @author  Prishepenko Stepan: Setest <itman116@gmail.com>
 * @package BP
 * @version 0.0.3-beta
 * @since   2018-09-25
 * @return  object
 */

/*
## [0.0.4-beta] - 2018-12-12
### Added
- добавил механизм управляющий используемыми TV параметрами в запросе
таким образом при клонировании запроса, мы можем управлять выборкой данных
с целью ускорения запроса, за счет его упрощения путем уменьшения подключаемых таблиц
Используется при работе с клонированными объектами xPDOQuery.
- scopeTvs:Array
- set_query()
- addScopeTVs()
- getScopeTVs()
- __clone() Исправляет клонирование объектов xPDOQuery

## [0.0.3-beta] - 2018-11-22
### Added
- метод joinTvById подключает TV используя в качестве имени их идентификатор
### Changed
- метод joinTv отключил перевод в нижний регистр имен полей

## [0.0.2-beta] - 2018-10-23
### Added
- метод joinCustomTable позволяет подключать в запрос любую таблицу БД
- метод getJoinType возвращает правильное наименование присоединения таблиц MySql

### Changed
- метод addWhere добавил вторым параметром тип соединения
 */

class QueryBuilder
{
    /** @var \modX $modx */
    public $modx;
    /** @var string $pk Primary key of class */
    protected $pk;
    public $pdoFetch;
    /** @var array тв параметры учавствующие в выборке where */
    protected $scopeTvs = [];
    /** @var xPDOQuery $query */
    protected $query;
    protected $joinTvs = [];
    protected $joinTvsById = [];
    protected $joinCustomTables = [];
    protected $className = 'modResource';

    public function __construct(\modX &$modx, &$pdoFetch = null)
    {
        $this->modx = &$modx;
        $this->pdoFetch = &$pdoFetch;
    }

    public function __clone()
    {
        // при клонировании далеко не все копируется, объекты становятся
        // доступными по ссылке, указывая на прототип
        if ($q_old = &$this->get_query() && $q = $this->set_query(clone $q_old)) {
            $q->sql = '';
            $q->stmt = null; // по идее этого достаточно
            $q->bindings = [];
            $q->cacheFlag = false;
            // unset($q->query['columns']);
        }
        // ??? а стоит ли ???
        // $this->clear();
        $this->joinTvsById = [];
        $this->joinCustomTables = [];
        // $this->joinTvs = [];
    }

    public function getScopeTVs(): array
    {
        return $this->scopeTvs;
    }

    public function addScopeTVs($tv): array
    {
        if ($tv && !in_array($tv['id'], $this->scopeTvs)) {
            $this->scopeTvs[$tv['id']] = $tv;
        }
        return $this->scopeTvs;
    }

    public function select()
    {
        $args = func_get_args();
        return call_user_func_array(array($this->query, 'select'), $args);
        // return $this->query->select($data);
    }

    /**
     * Get query object
     * @return xPDOQuery The resulting xPDOQuery instance or false if unsuccessful.
     */
    public function get_query(): \xPDOQuery
    {
        return $this->query;
    }

    public function set_query(\xPDOQuery $query)
    {
        // if ($query) {
        //   $this->query = $query;
        // }else{
        //   echo 'err';
        //   return false;
        // }
        // return $this->query ?: false;
        return ($query ? $this->query = $query : false);
    }

    /**
     *  Get the name of primary key field
     * @return string
     */
    public function get_pk()
    {
        return $this->pk;
    }

    /**
     * Converts the current xPDOQuery to parsed SQL.
     *
     * @param bool $parseBindings If true, bindings are parsed locally; otherwise
     * they are left in place.
     * @return string The parsed SQL query.
     */
    public function toSql($parseBindings = true)
    {
        if (!$this->query) {
            return false;
        }

        $this->query->prepare();
        return $this->query->toSql($parseBindings);
    }

    /**
     * Создает объект xPDOQuery
     * @param  string $className [description]
     * @param  array  $criteria  [description]
     * @return PDO |null
     */
    public function create($className = 'modResource', $criteria = array())
    {
        $this->className = $className;
        if (isset($this->query)) {
            unset($this->query);
        }

        $this->query = $this->modx->newQuery($className);

        $pk = $this->modx->getPK($className);
        $this->pk = is_array($pk)
        ? implode(',', $pk)
        : $pk;

        if ($criteria) {
            $this->addWhere($criteria);
        }

        // $this->query->where(array_merge(array(
        //    'published:=' => 1,
        //    'deleted:=' => 0,
        // ),$criteria));
        //
        // $c = $this->modx->newQuery($class);
        // // $c->select($this->modx->getSelectColumns($class, $class,'',array('group_id','createdby')));
        // $c->select($this->modx->getSelectColumns($class, $class,''));
        // $c->select($this->modx->getSelectColumns('modUser','user','user.',array('id','username')));
        // $c->select(array(
        //  '`profile`.`fullname` AS `user.fullname`',
        //  '`profile`.`firstname` AS `user.firstname`',

        return $this;
    }

    public function setLimit($offset = 0, $limit = 10, $is_page = false)
    {
        // $limit = intval(($limit ?: $this->config['limit']) ?: 10);
        $limit = intval($limit ?: 10);
        $offset = (int) $offset;
        if (!!$is_page) {
            $offset = $offset * $limit;
        }

        $this->query->limit($limit, $offset);
        return $this;
    }

    public function addWhere(array $data = array(), $conjunction = \xPDOQuery::SQL_AND)
    {
        if (!$data) {
            return $this;
        }

        $this->query->where($data, $conjunction);
        // echo '<pre>';
        // var_export($this->query->query);
        // die();

        return $this;
    }

    public function sortBy($sortBy = '', $sortDir = '')
    {
        // $this->query->sortby($sortBy, $sortDir);
        $this->query->query['sortby'][] = array('column' => $sortBy, 'direction' => $sortDir);
        return $this;
    }

    /**
     * Массовое подключение Tv параметров в запрос через метод joinTv
     * @param  array $tvs  массив TV параметров
     * @return
     */
    public function joinTvs($tvs)
    {
        if (!is_array($tvs)) {
            return false;
        }

        foreach ($tvs as $tv => $props) {
            call_user_func_array(array($this, 'joinTv'), $props);
        }
    }

    /**
     * Выполняет метод JOIN к запросу query с автоматическим добавлением данных
     * через метод select. Присоединяя таким образом TV параметры.
     *
     * @param  string  $name         имя TV параметра, можно явно указывать преобразование типа, например: price(int)
     * @param  string  $alias        псевдоним
     * @param  string  $alias_prefix префикс к псевдониму
     * @param  bool    $addSelect    автоматическое добавление TV в select объекта
     * @param  string  $type         тип присоединения: join,leftjoin,rightjoin,innerjoin
     * @param  string  $cast         тип преобразования типа в select-e, например "int"
     * @return object
     */
    public function joinTv($name = '', $alias = '', $alias_prefix = '', $addSelect = true, $type = 'leftJoin', $cast = null)
    {

        if (preg_match('/([^\(\)]*)(?:\((.*)\))?$/si', $name, $match)) {
            $name = trim($match[1]);
            $cast = $match[2] ? str_replace([' ', '(', ')'], '', $match[2]) : null;
        }

        if ($this->joinTvs[$name]) {
            return true;
        }

        $type = (in_array(strtolower($type), array('join', 'leftjoin', 'rightjoin', 'innerjoin'))) ? $type : 'join';
        // $name_lower = strtolower($name);
        $name_lower = $name;
        $alias = $alias ?: $name_lower;
        // $name_tv_chain = strtolower($name) . '_chain';
        $name_tv_chain = $name . '_chain';

        // The CAST() function converts a value (of any type) into the specified datatype.
        // можно использовать настоящий метод CAST, и тогда можно использовать любое преобразование
        switch ($cast) {
            case 'int':
            case 'integer':
                $cast = '+0';
                break;

            default:
                $cast = '';
                break;
        }
        // $q->{$type}('modTemplateVar', 'TV', "TV.id = TV{$name_lower}.tmplvarid");
        $this->query->{$type}('modTemplateVar', "TV{$name_tv_chain}", "TV{$name_tv_chain}.name = '{$name}'");
        // $this->query->{$type}('modTemplateVar', "TV{$name_tv_chain}", "TV{$name_lower}.name = '{$name}'");
        $this->query->{$type}('modTemplateVarResource', "TV{$name_lower}", "TV{$name_lower}.contentid = {$this->className}.id AND TV{$name_lower}.tmplvarid = TV{$name_tv_chain}.id");
        if ($addSelect) {
            $this->query->select("TV{$name_lower}.`value`{$cast} as `{$alias_prefix}{$alias}`");
        }

        $this->joinTvs[$name] = $name;

        // $c->leftJoin('modTemplateVar','tv_price',"tv_price.name = 'price'");
        // $c->leftJoin('modTemplateVarResource','tv_val_price',"tv_val_price.contentid = {$class}.id AND tv_price.id = tv_val_price.tmplvarid");
        return $this;
    }

    public function joinTvById($name = '', $alias = '', $alias_prefix = '', $addSelect = true, $type = 'leftJoin', $cast = null)
    {

        if (preg_match('/([^\(\)]*)(?:\((.*)\))?$/si', $name, $match)) {
            $name = trim($match[1]);
            $cast = $match[2] ? str_replace([' ', '(', ')'], '', $match[2]) : null;
        }

        if ($this->joinTvsById[$name]) {
            return true;
        }

        $type = (in_array(strtolower($type), array('join', 'leftjoin', 'rightjoin', 'innerjoin'))) ? $type : 'join';
        $tv_id = intval($name);
        // $tv_id = $name;
        $alias = $alias ?: $tv_id;
        // $name_tv_chain = strtolower($name) . '_chain';

        // The CAST() function converts a value (of any type) into the specified datatype.
        // можно использовать настоящий метод CAST, и тогда можно использовать любое преобразование
        switch ($cast) {
            case 'int':
            case 'integer':
                $cast = '+0';
                break;

            default:
                $cast = '';
                break;
        }
        // $q->{$type}('modTemplateVar', 'TV', "TV.id = TV{$tv_id}.tmplvarid");
        // $this->query->{$type}('modTemplateVar', "TV{$name_tv_chain}", "TV{$name_tv_chain}.name = '{$name}'");
        // $this->query->{$type}('modTemplateVar', "TV{$name_tv_chain}", "TV{$tv_id}.name = '{$name}'");
        $this->query->{$type}('modTemplateVarResource', "TV{$tv_id}", "TV{$tv_id}.contentid = {$this->className}.id AND TV{$tv_id}.tmplvarid = {$tv_id}");
        // if ($addSelect) $this->query->select("TV{$tv_id}.`value`{$cast} as `{$alias_prefix}{$alias}`");
        if ($addSelect) {
            $this->query->select("`TV{$tv_id}`.`value`{$cast} as `{$alias_prefix}{$alias}`");
        }

        $this->joinTvsById[$name] = $name;

        // $c->leftJoin('modTemplateVar','tv_price',"tv_price.name = 'price'");
        // $c->leftJoin('modTemplateVarResource','tv_val_price',"tv_val_price.contentid = {$class}.id AND tv_price.id = tv_val_price.tmplvarid");
        return $this;
    }

//   public function joinCustomTable($name = '', $alias = '', $alias_prefix = '', $addSelect = true, $type = 'leftJoin', $conditions = []){
    // public function joinCustomTable(...$args): QueryBuilder
    public function joinCustomTable($properties): QueryBuilder
    {
        // var_dump($args);
        // if (count($args) === 1 && \is_array($args[0])) {
            // $properties = $args[0];
        // } else {
            // $properties = $args;
        // }

        $properties = array_merge(
            [
                'name'         => '',
                'alias'        => '',
                'alias_prefix' => '',
                'addSelect'    => true,
                'type'         => 'leftJoin',
                'conditions'   => [],
                'onSelect'     => [],
                'on'           => '',
                'onVal'        => '',
                'onCustom'     => '',
            ],
            $properties
        );

        // var_dump($properties);

        [
            "name"         => $name,
            "alias"        => $alias,
            "alias_prefix" => $alias_prefix,
            "addSelect"    => $addSelect,
            "type"         => $type,
            "conditions"   => $conditions,
            "onSelect"     => $onSelect,
            "on"           => $on,
            "onVal"        => $onVal,
            "onCustom"     => $onCustom,
        ] = $properties;

        // var_dump($properties);

        $this->pdoFetch->addTime('Q: joinCustomTable: ' . print_r($properties, true));
        if (preg_match('/([^\(\)]*)(?:\((.*)\))?$/si', $name, $match)) {
            $name = trim($match[1]);
            // $cast = $match[2] ? str_replace([' ','(',')'], '', $match[2]) : null;
        }

        if ($this->joinCustomTables[$name]) {
            return $this;
        }

        $type = (in_array(strtolower($type), array('join', 'leftjoin', 'rightjoin', 'innerjoin'))) ? $type : 'join';
        $name_lower = strtolower($name);
        $alias = $alias ?: $name_lower;
        // $name_tv_chain = strtolower($name) . '_chain';

        // echo $table= $modx->getOption(xPDO::OPT_TABLE_PREFIX, null, '') . 'zzz';
        // $name = 'user_files';

        // FIXME !!! нужно дать выбор между $name как реальное имя таблицы и алиас из карты modx
        // нужно найти это в pdoTools там есть такой момент при выборке
        $table__prefix = $this->modx->getOption(\xPDO::OPT_TABLE_PREFIX, null, '');
        $table__name = (strpos($name, $table__prefix) === false) ? $table__prefix . $name : $name;
        $table__name_full = $this->modx->escape($this->modx->config['dbname'], '.') . '.' . $this->modx->escape($table__name);

        // echo ($modx->getTableName('user_files'));

        $table__columns_query = "DESCRIBE {$table__name_full};";
        // SHOW COLUMNS FROM [table name]
        $result = $this->modx->query($table__columns_query);
        if (!is_object($result) || !$table__columns = $result->fetchAll(\PDO::FETCH_ASSOC)) {
            // $this->pdoFetch->addTime('Q [ERROR]: не обнаружена таблица: ' . $table__name_full);
            throw new \Exception(__METHOD__ . 'Q [ERROR]: не обнаружена таблица: ' . $table__name_full);
        }
        $this->pdoFetch->addTime('Q: присоединяю таблицу: ' . $table__name_full);

        $pk = false;
        foreach ($table__columns as $column) {
            $pk = (!$pk && $column['Key'] == 'PRI') ? $column['Field'] : $pk;
            if ($addSelect) {
                $this->query->select("{$this->modx->escape($alias)}.`{$column['Field']}` as `{$alias_prefix}{$column['Field']}`");
            }
        }

        if ($pk) {
            $this->pdoFetch->addTime('Q: PK = ' . $pk);
        } else if (!$conditions) {
            // $this->pdoFetch->addTime('Q [ERROR]: PK не найден, также не указаны условия присоединения таблицы, прерываю работу' . $table__name_full);
            throw new \Exception(__METHOD__ . 'Q [ERROR]: PK не найден, также не указаны условия присоединения таблицы, прерываю работу' . $table__name_full);
        }

        $conditions_result = [];
        // print_r($conditions);
        // die();
        if ($conditions) {
            if (!empty($conditions['sql'])) {
                // echo 123; die();
                // не многомерный массив
                $conditions_result[] = new \xPDOQueryCondition(array(
                    'sql'         => $conditions['sql'],
                    'conjunction' => $conditions['conjunction'] ?: 'AND',
                ));
            } else {
                foreach ($conditions as $conf) {
                    // var_export($conf);
                    $conditions_result[] = new \xPDOQueryCondition(array(
                        'sql'         => $conf['sql'],
                        'conjunction' => $conf['conjunction'] ?: 'AND',
                    ));
                }
            }
        }

        $conditional_expr_field = $on ?? "{$this->className}.{$this->get_pk()}";
        $conditional_expr_val = $onVal ?? "{$alias}.{$pk}";

        if (!empty($onSelect)) {
            // $onSelect = [
            //     'className' => 'modResource',
            //     'select'    => [
            //         "modUserProfile" => "*",
            //     ],

            //     'where' =>  [
            //         'active:=' => 1,
            //     ],

            //     'limit' => 1
            // ];

            $onQuery = new QueryBuilder($this->modx, $this->pdoFetch);
            $onQuery->create($onSelect['className']);
            $onQuery_query = &$onQuery->get_query();

            // FIXME заменить $onQuery->select на addSelects() из AbstractFilter
            if ($onSelect['select']) {
                $onQuery->select($onSelect['select']);
            }

            if (!empty($where = $this->pdoFetch->additionalConditions($onSelect['where']))) {
                $onQuery->addWhere($where);
            }
            if ($onSelect['limit']) {
                $onQuery->setLimit(0, $onSelect['limit']);
            }

            if ($onSelect['alias']) {
                $onQuery_query->query['from']['tables'][0]['alias'] = $onSelect['alias'];
            }


            $conditional_expr_val = "({$onQuery->toSql()})";
            // echo $conditional_expr_val;
            // die();
        }

        $this->pdoFetch->addTime('Q: тип присоединения - ' . $this->getJoinType($type));
        $this->query->query['from']['joins'][] = array(
            'table' => $table__name,
            'class' => '',
            'alias' => $alias,
            'type' => $this->getJoinType($type),
            'conditions' => $conditions_result ? $conditions_result : array(
                new \xPDOQueryCondition(array(
                    'sql' => (!empty($onCustom) ? $onCustom : "{$conditional_expr_field} = {$conditional_expr_val}"),
                    'conjunction' => 'AND',
                )),
                // new xPDOQueryCondition(array('sql' => "{$classKey}.res_id IN (".implode(',', $pids).')' , 'conjunction' => 'AND'))
            ),
        );

        // $this->query->select($this->modx->getSelectColumns($table__name, $alias, $alias_prefix, $table__columns));

        $this->joinCustomTables[$name] = $name;
        return $this;
    }

    private function getJoinType($type = 'join')
    {
        $result = '';
        switch (strtolower($type)) {
            case 'leftjoin':
            case 'join':
                $result = \xPDOQuery::SQL_JOIN_LEFT;
                break;
            case 'naturalleftjoin':
                $result = \xPDOQuery::SQL_JOIN_NATURAL_LEFT;
                break;
            case 'naturalrightjoin':
                $result = \xPDOQuery::SQL_JOIN_NATURAL_RIGHT;
                break;
            case 'right':
            case 'rightjoin':
                $result = \xPDOQuery::SQL_JOIN_RIGHT;
                break;
            case 'straight':
                $result = \xPDOQuery::SQL_JOIN_STRAIGHT;
                break;
            case 'inner':
            case 'innerjoin':
            default:
                $result = \xPDOQuery::SQL_JOIN_CROSS;
                break;
        }
        return $result;
    }

    public function clear()
    {
        $this->joinTvs = [];
        $this->joinTvsById = [];
        $this->joinCustomTables = [];
    }
}
