<?php

declare(strict_types=1);

namespace BpFilter\Filter;

use BpFilter\Handler\Query\QueryBuilder as BpFilterQuery;

interface FilterInterface
{
    const VERSION = '6.3.3';

    // /**
    //  * Send an HTTP request.
    //  *
    //  * @param RequestInterface $request Request to send
    //  * @param array            $options Request options to apply to the given
    //  *                                  request and to the transfer.
    //  *
    //  * @return ResponseInterface
    //  * @throws GuzzleException
    //  */

    public function beforeProcess(): void;

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
    public function getPageId(array &$data = []) : int;


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
    public function getOffset(array &$data = []) : int;

    /**
     * Устанавливает и возвращает limit в соответсвии
     * с переданными критериями.
     *
     * В случае уничтожения этой переменную из переданного массиве
     * hash сумма всегда будет рассчитывать одинаково не зависимо
     * от переданного значения
     *
     * @param array $data
     * @return integer
     */
    public function getLimit(array &$data = []) : int;

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
