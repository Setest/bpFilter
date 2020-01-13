<?php

declare(strict_types=1);

namespace BpFilter\Filter;

use BpFilter\Handler\Query\QueryBuilder as BpFilterQuery;

interface CriteriaInterface
{

    public function getAppId() : string;

    /**
     * Устанавливает и возвращает страницу в соответствии
     * с переданными критериями.
     *
     * Обязательно уничтожаем эту переменную в переданном массиве
     * для исключения его из расчета хеш суммы
     *
     * @param array $data
     * @return integer
     */
    public function getPageId(array &$data = []) : int;

    /**
     * Добавляет критерии к запросу
     *
     * @param BpFilterQuery $query
     * @param string $criteria_name
     * @param void $criteria_value
     * @return void
     */
    public function addQueryCriteria(BpFilterQuery &$query, string $criteria_name, $criteria_value);


    /**
     * Подготавливает массив перед отправкой в чанк
     * @param  array  $rows
     * @return array
     */
    public function prepareRows(?array $rows): array;

    /**
     * Возвращает данные для построения объединенного запроса
     *
     * Вызывается в методе createChainSubQuery()
     *
     * @return array
     */
    public function addSubQueryCriteria(): array;
}
