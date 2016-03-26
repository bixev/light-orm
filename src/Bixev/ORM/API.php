<?php
namespace Bixev\ORM;

class API
{

    use \Bixev\LightLogger\LoggerTrait;

    /**
     * local cache
     *
     * @var array
     */
    static protected $_repositories = [];

    /**
     * pseudo-singleton factory
     *
     * @param string $className
     * @param \Bixev\LightLogger\LoggerInterface $logger
     * @return \Bixev\ORM\API
     */
    public static function get($className, \Bixev\LightLogger\LoggerInterface $logger = null)
    {
        if (!isset(self::$_repositories[$className])) {
            static::$_repositories[$className] = new static($className, $logger);
        }

        return static::$_repositories[$className];
    }

    /**
     * @var AbstractModel
     */
    protected $_className;

    /**
     * singleton usage
     *
     * @param string $className
     * @param \Bixev\LightLogger\LoggerInterface|null $logger
     * @throws \Exception
     */
    protected function __construct($className, \Bixev\LightLogger\LoggerInterface $logger = null)
    {
        if (!class_exists($className)) {
            throw new Exception(__METHOD__ . ' : Unknown model class : ' . $className);
        }
        $this->_className = $className;
        $this->setLogger($logger);
    }

    protected function getDatabaseName()
    {
        $class = $this->_className;

        return $class::getDatabaseName();
    }

    protected function getTableName()
    {
        $class = $this->_className;

        return $class::getTableName();
    }

    /**
     * search in database
     *
     * @param array $criteria array(fieldName=>fieldValue)
     *      fieldValue can be array('operator'=>,'value'=>)
     *      fieldValue can be array o values (sql will be field IN (...)
     * @param array $params
     *        - orderBy array(fieldName=>orientation)
     *        - limit
     *      - onlyCount bool=false : just return count of matching rows
     * @return \Bixev\ORM\Collection
     * @throws \Exception
     */
    public function findBy(array $criteria, array $params = [])
    {
        $onlyCount = isset($params['onlyCount']) && $params['onlyCount'];

        // Select
        $class = $this->_className;
        if ($onlyCount) {
            $selectSql = 'SELECT COUNT(id) AS count_id ';
        } else {
            $selectSql = 'SELECT ' . $class::getSqlFieldList() . ' ';
        }


        // From
        $fromSql = ' FROM ' . $this->getTableName() . ' ';

        // conditions
        $conditionSql = '';
        foreach ($criteria as $field => $value) {
            $class = $this->_className;
            if (!array_key_exists($field, $class::getFieldList())) {
                throw new Exception('Criteria field "' . $field . '" does not exist in ' . $class);
            }
            $fieldInfo = $class::getFieldInfos($field);
            $conditionSql .= $conditionSql ? ' AND ' : ' WHERE ';
            $conditionSql .= Database::get($this->getDatabaseName())->backquote($field);
            if (is_null($value)) {
                $conditionSql .= ' IS NULL';
            } elseif (is_array($value)) {
                if (isset($value['operator'])) {
                    $conditionSql .= Database::get($this->getDatabaseName())->p($value['operator']);
                    if (isset($value['value'])) {
                        switch ($fieldInfo['type']) {
                            case 'int':
                                $v = (int)$value['value'];
                                break;
                            case 'float':
                                $v = (float)$value['value'];
                                break;
                            default:
                                $v = Database::get($this->getDatabaseName())->getConnector()->quote($value['value']);
                                break;
                        }
                        $conditionSql .= ' ' . $v;
                    }
                } else {
                    $valuesSql = '';
                    foreach ($value as $v) {
                        $valuesSql .= $valuesSql ? ',' : '';
                        switch ($fieldInfo['type']) {
                            case 'int':
                                $v = (int)$v;
                                break;
                            case 'float':
                                $v = (float)$v;
                                break;
                            default:
                                $v = Database::get($this->getDatabaseName())->getConnector()->quote($v);
                                break;
                        }
                        $valuesSql .= $v;
                    }
                    $conditionSql .= ' IN (' . $valuesSql . ')';
                }
            } else {
                switch ($fieldInfo['type']) {
                    case 'int':
                        $v = (int)$value;
                        break;
                    case 'float':
                        $v = (float)$value;
                        break;
                    default:
                        $v = Database::get($this->getDatabaseName())->getConnector()->quote($value);
                        break;
                }
                $conditionSql .= " = " . $v;
            }
        }

        // fullText
        if (isset($params['fullText'])) {
            $conditionSql .= $conditionSql ? ' AND ' : ' WHERE ';
            $conditionSql .= $params['fullText'];
        }

        // order by
        $orderBySql = '';
        if (isset($params['orderBy'])) {
            foreach ($params['orderBy'] as $fieldName => $orientation) {
                $orderBySql .= $orderBySql ? ', ' : ' ORDER BY ';
                $orderBySql .= Database::get($this->getDatabaseName())->backquote($fieldName) . ' ' . Database::get($this->getDatabaseName())->p($orientation);
            }
        }

        // limit
        $limitSql = '';
        if (isset($params['limit'])) {
            $limitSql = ' LIMIT ' . Database::get($this->getDatabaseName())->p($params['limit']);
        }

        // On aggrège tout ça !
        $sql = $selectSql
            . $fromSql
            . $conditionSql
            . $orderBySql
            . $limitSql;

        if ($onlyCount) {
            $row = Database::get($this->getDatabaseName())->getConnector()->query($sql)->fetch();

            return $row['count_id'];
        } else {
            // On récupère les infos
            $rows = Database::get($this->getDatabaseName())->getConnector()->query($sql)->fetchAll();

            $collection = $this->newCollection($rows);

            return $collection;
        }
    }

    /**
     *
     * @param array $params @see \Bixev\ORM\API::findBy()
     * @return int
     */
    public function count($params)
    {
        return $this->findBy($params, ['onlyCount' => true]);
    }

    /**
     * returns a collection initialised with rows
     *
     * @param array $rows
     * @return Collection
     */
    public function newCollection(array $rows = [])
    {
        $collection = new Collection($this->_className);

        foreach ($rows as $row) {
            $collection->addByRow($row);
        }

        return $collection;
    }

    /**
     * returns ONE record
     * if many throw exception
     * if none returns null
     *
     * @param array $criteria array(fieldName=>fieldValue)
     * @param array $params
     *        - orderBy array(fieldName=>orientation)
     *        - limit
     * @return AbstractModel
     * @throws \Exception
     */
    public function findOneBy(array $criteria = [], array $params = [])
    {
        $params['limit'] = 1;
        $collection = $this->findBy($criteria, $params);
        if (count($collection) > 1) {
            throw new Exception(__METHOD__ . ' : More than one result found');
        } elseif (count($collection) == 0) {
            return null;
        } else {
            return $collection[0];
        }
    }

    /**
     * returns ONE record corresponding to id
     *
     * @param int $id
     * @return AbstractModel
     */
    public function find($id)
    {
        return $this->findOneBy(['id' => $id]);
    }

    /**
     * Returns collection of ALL records
     *
     * @param array $params
     *        - orderBy array(fieldName=>orientation)
     *        - limit
     * @return Collection
     */
    public function findAll(array $params = [])
    {
        return $this->findBy([], $params);
    }

    /**
     * Instanciate new class
     *
     * @param mixed $input same input as Model instanciation
     * @param $secondArg
     * @return mixed
     */
    public function createNew($input = 'new', $secondArg = '')
    {
        $class = $this->_className;

        return new $class($input, $secondArg);
    }
}
