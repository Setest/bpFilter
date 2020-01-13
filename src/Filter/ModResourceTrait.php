<?php

declare(strict_types=1);

namespace BpFilter\Filter;

/**
 * Default properties for filters used class modResource
 */
trait ModResourceTrait
{
    /**
     * Undocumented variable
     *
     * @var array
     */
    public $config = [
        'tpl'                      => '@FILE:ifilter/row_project.tpl', //@CHUNK: orderServicesItem'
        'tplWrap'                  => '@INLINE:{$output}',

        'limit'                    => 15,

        'className'                => 'modResource',
        'useWeblinkUrl'            => false,
        'showLog'                  => false, // срабатывает только для pdoFetch, при true данные попадают в мой log
        'setTotal'                 => true,
        'totalVar'                 => 'total',
        'return'                   => 'chunks',    // chunks, data, sql or ids
        'where'                    => [],
        'includeContent'           => 0,

        // 'select'                   => 'id,pagetitle,uri', // можно передать список основных полей через запятую
        'select'                   => [
            "modResource"        => "id,pagetitle,uri",
        ],

        'where'                    =>  [],
        // 'where'                    => '{"template":"6"}',

        'where_default'            =>  [
          'published:=' => 1,
          'deleted:='   => 0,
          // 'modResource.context_key:=' => $this->config['ctx'],
        ],

        'tvPrefix'                 => 'TV',
        'includeTVs'               => '',
    ];
}
