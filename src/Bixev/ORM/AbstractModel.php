<?php
namespace Bixev\ORM;

abstract class AbstractModel implements \ArrayAccess
{

    /**
     * database to use
     *
     * @var string
     */
    static protected $database;

    /**
     * table name
     *
     * @var string
     */
    static protected $table;

    /**
     * available fields
     * array of 'FIELDNAME' => ['type'=>'TYPE', size=>'SIZE']
     * type : bool | int | str | float | date | dateTime | time
     * size : integer (0 for no limit), if type=float size is string "int,int"
     *
     * @var array
     */
    static protected $fieldList = [];

    /**
     * array of relations : [{field:XXX,class:XXX},{...},...]
     *
     * @var array
     */
    static protected $relationList = [];

    /**
     * @var array of AbstractModel (array[recordClass]’recordId] = Record)
     */
    protected $_relations = [];

    /**
     * allowed field types
     *
     * @var array
     */
    static protected $fieldTypes = ['int', 'bool', 'str', 'float', 'date', 'dateTime', 'time'];

    const ON_DUPLICATE_KEY_UPDATE = 'update';
    const ON_DUPLICATE_KEY_IGNORE = 'ignore';

    /**
     * update or ignore sql line when duplicate key error occurs
     *
     * @var string : see AbstractModel::ON_DUPLICATE_KEY_...
     */
    static protected $_onDuplicateKey = '';

    // end of model config
    //###########################################################//

    /**
     * @var int
     */
    private $id;

    /**
     * values of record
     *
     * @var array
     */
    protected $fieldValues;

    /**
     * is record existing in database (not creation)
     *
     * @var bool
     */
    private $recordExists = false;

    /**
     * @var int
     */
    private $RecordUpdateDate;

    /**
     * @var array
     */
    protected $modifiedValues = [];

    // local cache
    static protected $_sqlFieldList = [];

    const INSTANCIATION_METHOD_NEW = 'new';
    const INSTANCIATION_METHOD_ID = 'id';
    const INSTANCIATION_METHOD_ROW = 'row';
    protected $_instanciationMethod;

    final public function __construct($firstArg = 'new', $secondArg = '')
    {

        if (array_search($firstArg, ['new', 'id', 'row']) !== false) {
            // method as first argument
            $method = $firstArg;
            $data = $secondArg;
        } else if (array_search($secondArg, ['new', 'id', 'row']) !== false) {
            // method as second argument
            $method = $secondArg;
            $data = $firstArg;
        } else if (is_int($firstArg)) {
            // integer : instanciate with id
            $method = 'id';
            $data = $firstArg;
        } else if (is_array($firstArg)) {
            // array : instanciate with row
            $method = 'row';
            $data = $firstArg;
        } else if (intval($firstArg) > 0) {
            // anything else : instanciate with id
            $method = 'id';
            $data = intval($firstArg);
        } else {
            $method = $data = '';
        }

        if ($method == 'new' && $data == '') {
            $this->_instanciationMethod = static::INSTANCIATION_METHOD_NEW;
            $this->id = 0;
        } elseif ($method == 'id' && intval($data) != 0) {
            $this->_instanciationMethod = static::INSTANCIATION_METHOD_ID;
            $this->loadFromId(intval($data));
        } elseif ($method == 'row' && is_array($data)) {
            $this->_instanciationMethod = static::INSTANCIATION_METHOD_ROW;
            $this->loadFromRow($data);
        } else {
            throw new Exception("unknown instanciation method");
        }
        $this->fieldValues['id'] = $this->id;
    }

    /**
     * @return int : record id in database
     * @throws Exception
     */
    final public function save()
    {
        $isNew = !$this->recordExists;

        $this->preSaveActions($isNew);

        $this->checkFieldsValues();
        if (!$this->recordExists) {
            // update date
            $oDateNow = new \DateTime('NOW', new \DateTimeZone('Europe/Paris'));
            $dateNow = $oDateNow->format('Y-m-d H:i:s');
            $this->RecordUpdateDate = $dateNow;

            // insert
            $id = $this->insertInDb();
            $this->id = $id;

            $this->recordExists = true;
            $return = $id;
        } else {
            // if no update, no need to store
            if (count($this->modifiedValues) == 0) {
                $return = $this->id;
            } else {
                $return = $this->updateInDb();
            }
        }

        if ($return == 0) {
            return 0;
        } else {
            $this->modifiedValues = [];

            $this->postSaveActions($isNew);

            return $return;
        }
    }

    final protected function insertInDb()
    {
        $insert_id = 0;

        $fields = [];
        foreach (static::$fieldList as $fieldName => $fieldInfos) {
            // only given values
            if (isset($this->fieldValues[$fieldName])) {
                $fields[$fieldName] = $this->fieldValues[$fieldName];
            }
            // if id is given, we dont want to use last_insert_id
            if ($fieldName == 'id') {
                $insert_id = $this->fieldValues[$fieldName];
            }
        }
        if (static::$_onDuplicateKey == static::ON_DUPLICATE_KEY_IGNORE) {
            $insert = Database::get($this->getDatabaseName())->I(static::$table, $fields, true);
        } elseif (static::$_onDuplicateKey == static::ON_DUPLICATE_KEY_UPDATE) {
            $insert = Database::get($this->getDatabaseName())->I(static::$table, $fields, false, true);
        } else {
            $insert = Database::get($this->getDatabaseName())->I(static::$table, $fields);
        }
        if ($insert === false) {
            throw new Exception("Error while inserting record in database");
        } else {
            if ($insert_id == 0) {
                $insert_id = Database::get($this->getDatabaseName())->getConnector()->lastInsertId();
            }
            $this->id = (int)$insert_id;

            return $this->id;
        }
    }

    /**
     * @return int : record id
     */
    final protected function updateInDb()
    {
        $fields = [];
        foreach (static::$fieldList as $fieldName => $fieldInfos) {
            // only given values
            if ($fieldName != 'id') {
                if (isset($this->fieldValues[$fieldName])) {
                    $fields[$fieldName] = $this->fieldValues[$fieldName];
                } else {
                    $fields[$fieldName] = null;
                }
            }
        }

        Database::get($this->getDatabaseName())->U(static::$table, $fields, $this->getId());

        return $this->id;
    }

    /**
     * @return bool
     * @throws Exception
     */
    final public function delete()
    {
        if (!$this->preDeleteActions()) {
            return false;
        }
        if ($this->deleteFromDatabase()) {
            $this->postDeleteActions();
            $this->id = 0;

            return true;
        } else {
            return false;
        }
    }

    /**
     * @return bool
     * @throws Exception
     */
    final protected function deleteFromDatabase()
    {
        $result = Database::get($this->getDatabaseName())->D(static::$table, $this->getId());

        if (!$result) {
            throw new Exception("deleteFromDatabase: Erreur lors de la suppression de la base de données");
        } else {
            return true;
        }
    }

    /**
     * @param $id
     * @return bool
     * @throws Exception
     */
    final protected function loadFromId($id)
    {

        if (static::$table == '') {
            throw new Exception("loadFromId : aucune table définie pour charger l'objet");
        }
        $this->id = $id;
        $fields = '';
        foreach (static::$fieldList as $fieldName => $fieldInfos) {
            $fields .= ($fields != '') ? ',' : '';
            $fields .= '`' . $fieldName . '`';
        }
        $sql = "SELECT " . $fields . " FROM " . Database::get($this->getDatabaseName())->backquote(static::$table) . " WHERE id = " . intval($id);
        $row = Database::get($this->getDatabaseName())->getConnector()->query($sql)->fetch();

        if ($row == '' || !count($row)) {
            throw new Exception("no record with id=" . $id . " on table " . static::$table);
        }

        $this->loadFromRow($row);
    }

    /**
     * @param $row
     * @throws Exception
     */
    final protected function loadFromRow($row)
    {

        if (!isset($row['id']) || intval($row['id']) == 0) {
            throw new Exception("required id on row");
        } else {
            $this->recordExists = true;
            $this->id = (int)$row['id'];
        }

        foreach (static::$fieldList as $fieldName => $fieldInfos) {
            if (!array_key_exists($fieldName, $row)) {
                throw new Exception("missing field on row : " . $fieldName);
            } else {
                if ($fieldInfos['type'] == 'int') {
                    $this->fieldValues[$fieldName] = (int)$row[$fieldName];
                } elseif ($fieldInfos['type'] == 'float') {
                    $this->fieldValues[$fieldName] = (float)$row[$fieldName];
                } elseif ($fieldInfos['type'] == 'bool') {
                    $this->fieldValues[$fieldName] = (bool)$row[$fieldName];
                } elseif ($fieldInfos['type'] == 'int') {
                    if ($row[$fieldName] === null) {
                        $this->fieldValues[$fieldName] = null;
                    } else {
                        $this->fieldValues[$fieldName] = (int)$row[$fieldName];
                    }
                } else {
                    $this->fieldValues[$fieldName] = $row[$fieldName];
                }
            }
        }
    }

    /**
     * @return bool
     */
    final public function isValid()
    {
        if ($this->id == -1) {
            return false;
        }

        return $this->checkFieldsValues();
    }

    /**
     * @return bool
     * @throws Exception
     */
    final protected function checkFieldsValues()
    {
        foreach (static::$fieldList as $fieldName => $fieldInfos) {
            if (!isset($this->fieldValues[$fieldName]) && $fieldInfos['required']) {
                throw new Exception("missing required field : " . $fieldName);
            } elseif (isset($this->fieldValues[$fieldName])) {
                if ($fieldInfos['type'] == 'str' || $fieldInfos['type'] == 'string') {
                    if (!is_string($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    } else {
                        if ($fieldInfos['required'] && strlen($this->fieldValues[$fieldName]) == 0) {
                            throw new Exception("empty field (string) : " . $fieldName);
                        }
                        if (($fieldInfos['size'] != 0 && $fieldInfos['size'] < strlen($this->fieldValues[$fieldName]))) {
                            throw new Exception("too long field (string) : " . $fieldName . " (" . $fieldInfos['size'] . ")");
                        }
                    }
                } elseif ($fieldInfos['type'] == 'int') {
                    if (!is_int($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    } elseif ($this->fieldValues[$fieldName] == 0 && $fieldInfos['required'] && $fieldName != 'id') {
                        throw new Exception("required field (int) : " . $fieldName . " (" . $fieldInfos['size'] . ")");
                    } elseif (($fieldInfos['size'] != 0 && $fieldInfos['size'] < strlen($this->fieldValues[$fieldName]))) {
                        throw new Exception("too long field (int) : " . $fieldName . " (" . $fieldInfos['size'] . ")");
                    }
                } elseif ($fieldInfos['type'] == 'float') {
                    if (!is_float($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    } elseif ($fieldInfos['size'] != 0) {
                        $type_var = explode(',', $fieldInfos['size']);
                        $var = explode('.', $this->fieldValues[$fieldName]);
                        if (strlen($var[0]) > $type_var[0]) {
                            throw new Exception("too long field (float, before comma) : " . $fieldName . " (" . $fieldInfos['size'] . ")");
                        }
                        if (isset($var[1]) && strlen($var[1]) > $type_var[1]) {
                            throw new Exception("too long field (float, after comma) : " . $fieldName . " (" . $fieldInfos['size'] . ")");
                        }
                    }
                } elseif ($fieldInfos['type'] == 'bool') {
                    if (!is_bool($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    }
                } elseif ($fieldInfos['type'] == 'date') {
                    if (!is_string($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    } elseif (preg_match('#[1-9][0-9]{3}-[1-12]-[1-31]#', $this->fieldValues[$fieldName]) === false) {
                        throw new Exception("field value does not match (date) : " . $fieldName);
                    }
                } elseif ($fieldInfos['type'] == 'dateTime') {
                    if (!is_string($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    } elseif (preg_match('#[1-9][0-9]{3}-[1-12]-[1-31] [0-23]:[0-59]:[0-59]#', $this->fieldValues[$fieldName]) === false) {
                        throw new Exception("field value does not match (dateTime) : " . $fieldName);
                    }
                } elseif ($fieldInfos['type'] == 'time') {
                    if (!is_string($this->fieldValues[$fieldName])) {
                        throw new Exception("wrong field type : " . $fieldName . " (expecting " . $fieldInfos['type'] . ") value : " . $this->fieldValues[$fieldName] . " (got " . gettype($this->fieldValues[$fieldName]) . ")");
                    } elseif (preg_match('#[0-23]:[0-59]:[0-59]#', $this->fieldValues[$fieldName]) === false) {
                        throw new Exception("field value does not match (time) : " . $fieldName);
                    }
                }
            }
        }

        return true;
    }

    /**
     * @param $fieldName
     * @param $value
     * @return bool
     * @throws Exception
     */
    final public function __set($fieldName, $value)
    {

        // protected fields
        if ($fieldName == 'fieldList' || $fieldName == 'table' || $fieldName == 'id' || $fieldName == 'RecordUpdateDate' || $fieldName == 'errors') {
            throw new Exception("forbidden direct write on field : " . $fieldName);
        }

        if (!array_key_exists($fieldName, static::$fieldList)) {
            throw new Exception("unknown field " . $fieldName . " -- value : " . $value . " (" . gettype($value) . ")");
        }

        // store previous value
        if (isset($this->fieldValues[$fieldName])) {
            $oldValue = $this->fieldValues[$fieldName];
        } else {
            $oldValue = null;
        }

        // cast new value
        if (static::$fieldList[$fieldName]['type'] == 'int') {
            $newValue = (int)$value;
        } elseif (static::$fieldList[$fieldName]['type'] == 'float') {
            $newValue = (float)str_replace(',', '.', $value);
        } elseif (static::$fieldList[$fieldName]['type'] == 'bool') {
            $newValue = (bool)$value;
        } elseif (static::$fieldList[$fieldName]['type'] == 'int' && $this->fieldValues[$fieldName] !== null) {
            $newValue = (int)$value;
        } else {
            $newValue = $value;
        }

        // store new value
        $this->fieldValues[$fieldName] = $newValue;

        // store modification
        if ($oldValue !== $newValue) {
            if (isset($this->modifiedValues[$fieldName])) {
                $this->modifiedValues[$fieldName]['new'] = $newValue;
            } else {
                $this->modifiedValues[$fieldName] = [];
                $this->modifiedValues[$fieldName]['old'] = $oldValue;
                $this->modifiedValues[$fieldName]['new'] = $newValue;
            }
        }
    }

    /**
     * @param $fieldName
     * @return mixed|null
     * @throws Exception
     */
    final public function __get($fieldName)
    {
        // protected fields
        if ($fieldName == 'fieldList' || $fieldName == 'table' || $fieldName == 'id' || $fieldName == 'RecordUpdateDate' || $fieldName == 'errors') {
            throw new Exception("__get : Tentative de récupération directe de l'attribut : " . $fieldName);

        }

        if (array_key_exists($fieldName, static::$fieldList)) {
            $return = (isset($this->fieldValues[$fieldName])) ? $this->fieldValues[$fieldName] : null;

            return $return;
        } elseif (array_key_exists($fieldName, static::$relationList)) {
            $return = $this->getRelationById($fieldName);

            return $return;
        } else {
            throw new Exception("unknown field " . $fieldName);
        }
    }

    /**
     * @return int
     */
    final public function getId()
    {
        return (int)$this->id;
    }

    /**
     * @param $id
     */
    final public function setId($id)
    {
        $this->fieldValues['id'] = intval($id);
        $this->id = intval($id);
    }

    /**
     * @param $isNew
     * @return bool
     */
    protected function postSaveActions($isNew)
    {
        return true;
    }

    /**
     * @param $isNew
     * @return bool
     */
    protected function preSaveActions($isNew)
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function preDeleteActions()
    {
        return true;
    }

    /**
     * @return bool
     */
    protected function postDeleteActions()
    {
        return true;
    }

    /**
     * @return array
     */
    final public function getModifications()
    {
        return $this->modifiedValues;
    }


    /**
     * @param $fieldName
     * @return mixed|null
     */
    final public function getOldValue($fieldName)
    {
        if (!isset($this->modifiedValues[$fieldName])) {
            return $this->$fieldName;
        } else {
            return $this->modifiedValues[$fieldName]['old'];
        }
    }

    /**
     * @return array
     */
    final public function getRow()
    {
        $toReturn = [];
        foreach (static::$fieldList as $fieldName => $fieldInfos) {
            if (isset($this->fieldValues[$fieldName])) {
                $toReturn[$fieldName] = $this->fieldValues[$fieldName];
            } else {
                $toReturn[$fieldName] = null;
            }
        }

        return $toReturn;
    }

    //#####     ArrayAccess     #####//
    public function offsetExists($offset)
    {
        return array_key_exists($offset, static::$fieldList);
    }

    public function offsetGet($offset)
    {
        return $this->__get($offset);
    }

    public function offsetSet($offset, $value)
    {
        return $this->__set($offset, $value);
    }

    public function offsetUnset($offset)
    {
        throw new Exception("unset : Tentative d'effacement directe de l'attribut : " . $offset);
    }
    //#####   end ArrayAccess   #####//

    /**
     * @return string
     */
    final public static function getTableName()
    {
        return static::$table;
    }

    /**
     * @return string
     */
    final public static function getDatabaseName()
    {
        return static::$database;
    }

    /**
     * @param $fieldName
     * @return mixed
     */
    public static function getFieldInfos($fieldName)
    {
        return static::$fieldList[$fieldName];
    }

    /**
     * @return array
     */
    public static function getFieldList()
    {
        return static::$fieldList;
    }

    /**
     * @return array
     */
    public static function getFieldTypes()
    {
        return static::$fieldTypes;
    }

    /**
     * @return array
     */
    public static function getRelationList()
    {
        return static::$relationList;
    }

    /**
     * @param null $tableAlias
     * @return mixed
     */
    public static function getSqlFieldList($tableAlias = null)
    {
        if (!isset(static::$_sqlFieldList[static::getTableName() . ' / ' . $tableAlias])) {
            $list = '';
            foreach (static::$fieldList as $field => $infos) {
                if ($list != '') {
                    $list .= ',';
                }
                if ($tableAlias !== null) {
                    $list .= Database::get(static::getDatabaseName())->backquote($tableAlias);
                    $list .= '.';
                }
                $list .= Database::get(static::getDatabaseName())->backquote($field);
            }
            static::$_sqlFieldList[static::getTableName() . ' / ' . $tableAlias] = $list;
        }

        return static::$_sqlFieldList[static::getTableName() . ' / ' . $tableAlias];
    }

    /**
     * @param $objectName
     * @return mixed
     * @throws Exception
     */
    public function getRelationById($objectName)
    {
        if (!isset(static::$relationList[$objectName])) {
            throw new Exception("unknown relation : " . $objectName);
        }

        if (!isset($this->_relations[$objectName])) {
            $repo = API::get(static::$relationList[$objectName]['class']);
            $fieldName = static::$relationList[$objectName]['field'];
            $this->_relations[$objectName] = $repo->find($this->$fieldName);
        }

        return $this->_relations[$objectName];
    }

}
