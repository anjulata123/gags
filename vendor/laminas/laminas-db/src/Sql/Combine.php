<?php

namespace Laminas\Db\Sql;

use Laminas\Db\Adapter\Driver\DriverInterface;
use Laminas\Db\Adapter\ParameterContainer;
use Laminas\Db\Adapter\Platform\PlatformInterface;

use function array_key_exists;
use function array_merge;
use function get_class;
use function gettype;
use function is_array;
use function is_object;
use function sprintf;
use function strtoupper;
use function trim;

/**
 * Combine SQL statement - allows combining multiple select statements into one
 */
class Combine extends AbstractPreparableSql
{
    public const COLUMNS           = 'columns';
    public const COMBINE           = 'combine';
    public const COMBINE_UNION     = 'union';
    public const COMBINE_EXCEPT    = 'except';
    public const COMBINE_INTERSECT = 'intersect';

    /** @var string[] */
    protected $specifications = [
        self::COMBINE => '%1$s (%2$s) ',
    ];

    /** @var Select[][] */
    private $combine = [];

    /**
     * @param Select|array|null $select
     * @param string            $type
     * @param string            $modifier
     */
    public function __construct($select = null, $type = self::COMBINE_UNION, $modifier = '')
    {
        if ($select) {
            $this->combine($select, $type, $modifier);
        }
    }

    /**
     * Create combine clause
     *
     * @param Select|array $select
     * @param string $type
     * @param string $modifier
     * @return $this Provides a fluent interface
     * @throws Exception\InvalidArgumentException
     */
    public function combine($select, $type = self::COMBINE_UNION, $modifier = '')
    {
        if (is_array($select)) {
            foreach ($select as $combine) {
                if ($combine instanceof Select) {
                    $combine = [$combine];
                }

                $this->combine(
                    $combine[0],
                    $combine[1] ?? $type,
                    $combine[2] ?? $modifier
                );
            }
            return $this;
        }

        if (! $select instanceof Select) {
            throw new Exception\InvalidArgumentException(sprintf(
                '$select must be a array or instance of Select, "%s" given',
                is_object($select) ? get_class($select) : gettype($select)
            ));
        }

        $this->combine[] = [
            'select'   => $select,
            'type'     => $type,
            'modifier' => $modifier,
        ];
        return $this;
    }

    /**
     * Create union clause
     *
     * @param Select|array $select
     * @param string       $modifier
     * @return $this
     */
    public function union($select, $modifier = '')
    {
        return $this->combine($select, self::COMBINE_UNION, $modifier);
    }

    /**
     * Create except clause
     *
     * @param Select|array $select
     * @param string       $modifier
     * @return $this
     */
    public function except($select, $modifier = '')
    {
        return $this->combine($select, self::COMBINE_EXCEPT, $modifier);
    }

    /**
     * Create intersect clause
     *
     * @param Select|array $select
     * @param string $modifier
     * @return $this
     */
    public function intersect($select, $modifier = '')
    {
        return $this->combine($select, self::COMBINE_INTERSECT, $modifier);
    }

    /**
     * Build sql string
     *
     * @return string
     */
    protected function buildSqlString(
        PlatformInterface $platform,
        ?DriverInterface $driver = null,
        ?ParameterContainer $parameterContainer = null
    ) {
        if (! $this->combine) {
            return;
        }

        $sql = '';
        foreach ($this->combine as $i => $combine) {
            $type   = $i === 0
                    ? ''
                    : strtoupper($combine['type'] . ($combine['modifier'] ? ' ' . $combine['modifier'] : ''));
            $select = $this->processSubSelect($combine['select'], $platform, $driver, $parameterContainer);
            $sql   .= sprintf(
                $this->specifications[self::COMBINE],
                $type,
                $select
            );
        }
        return trim($sql, ' ');
    }

    /**
     * @return $this Provides a fluent interface
     */
    public function alignColumns()
    {
        if (! $this->combine) {
            return $this;
        }

        $allColumns = [];
        foreach ($this->combine as $combine) {
            $allColumns = array_merge(
                $allColumns,
                $combine['select']->getRawState(self::COLUMNS)
            );
        }

        foreach ($this->combine as $combine) {
            $combineColumns = $combine['select']->getRawState(self::COLUMNS);
            $aligned        = [];
            foreach ($allColumns as $alias => $column) {
                $aligned[$alias] = $combineColumns[$alias] ?? new Predicate\Expression('NULL');
            }
            $combine['select']->columns($aligned, false);
        }
        return $this;
    }

    /**
     * Get raw state
     *
     * @param string $key
     * @return array
     */
    public function getRawState($key = null)
    {
        $rawState = [
            self::COMBINE => $this->combine,
            self::COLUMNS => $this->combine
                                ? $this->combine[0]['select']->getRawState(self::COLUMNS)
                                : [],
        ];
        return isset($key) && array_key_exists($key, $rawState) ? $rawState[$key] : $rawState;
    }
}
