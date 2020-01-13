<?php

/**
 * Фабрика получения необходимого типа фильтрации
 *
 * @author Prishepenko Stepan: Setest <itman116@gmail.com>
 * @version 0.1.0
 * @created 2019-12-25T
 * @since 2019-12-25
 */

declare(strict_types=1);

namespace BpFilter;

use BpFilter\Filter\FilterInterface;

class FilterFactory
{
    /**
     * Undocumented function
     *
     * @param String $appId
     * @return FilterInterface
     */
    final public static function getFilter(String $appId = 'Projects', ...$args): FilterInterface
    {
        // $className = 'BpFilter\\' . ucfirst($appId) . 'Filter';
        $className = 'BpFilter\\' . 'Filter\\' . ucfirst($appId);
        $class = new \ReflectionClass($className);
        return $class->newInstanceArgs($args);
    }
}
