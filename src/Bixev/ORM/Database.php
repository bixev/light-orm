<?php
namespace Bixev\ORM;

class Database
{
    static protected $_instances = [];

    /**
     * @var \PDO
     */
    protected $_connector;

    /**
     * pseudo-singleton factory
     *
     * @param string $databaseName
     * @return mixed
     */
    static public function get($databaseName = null)
    {
        if (!isset(static::$_instances[$databaseName])) {
            static::$_instances[$databaseName] = new static($databaseName);
        }

        return static::$_instances[$databaseName];
    }

    protected function __construct($databaseName = null)
    {
        $this->_connector = db($databaseName);
    }

    public function backquote($str)
    {
        return str_replace("'", '`', $this->_connector->quote($str));
    }

    public function p($str)
    {
        return str_replace("'", '', $this->_connector->quote($str));
    }

    /**
     * Delete on id
     *
     * @param string $table : table
     * @param int $id : id
     * @return bool : delete ok
     */
    public function D($table, $id)
    {
        $sql = "DELETE FROM " . $this->backquote($table) . " WHERE id = " . intval($id);

        return $this->_connector->exec($sql);
    }

    /**
     * Insert
     *
     * @param string $table
     * @param array $values : array(key=>value) to insert
     * @param bool $ignore : insert ignore
     * @param bool $update : on duplicate key update
     * @return int : id of inserted record
     */
    public function I($table, array $values, $ignore = false, $update = false)
    {
        $fields = '';
        $fieldValues = '';
        $onDuplicateKey = $onDuplicateKeyFields = '';
        foreach ($values as $field => $value) {
            $fields .= $fields != '' ? ',' : '';
            $onDuplicateKeyFields .= $onDuplicateKeyFields != '' ? ',' : '';
            $fields .= $this->backquote($field);
            $onDuplicateKeyFields .= $this->backquote($field);
            $onDuplicateKeyFields .= '=';
            $fieldValues .= $fieldValues != '' ? ',' : '';
            $fieldValues .= $this->_connector->quote($value);
            $onDuplicateKeyFields .= $this->_connector->quote($value);
        }
        if ($update) {
            $onDuplicateKey .= ' ON DUPLICATE KEY UPDATE ' . $onDuplicateKeyFields;
        }
        $sql = "INSERT " . ($ignore ? "IGNORE " : '') . "INTO " . $this->backquote($table) . "(" . $fields . ") VALUES(" . $fieldValues . ") " . $onDuplicateKey;

        return $this->_connector->exec($sql);
    }

    /**
     * Update on id
     *
     * @param string $table
     * @param array $values :  array(key=>value)
     * @param int $id
     * @return bool : update ok
     * @throws \Exception
     */
    public function U($table, array $values, $id = 0)
    {

        $id = intval($id);
        if (!is_int($id) || $id == 0) {
            throw new \Exception('Wrong id for update in db');
        }

        $sql = '';
        foreach ($values as $field => $value) {
            $sql .= $sql != '' ? ',' : '';
            if ($value === null) {
                $sql .= $this->backquote($field) . "=NULL";
            } else {
                $sql .= $this->backquote($field) . "=" . $this->_connector->quote($value);
            }
        }
        $sql = "UPDATE " . $this->backquote($table) . " SET " . $sql . " WHERE id = " . intval($id) . "";

        return $this->_connector->exec($sql);
    }

}
