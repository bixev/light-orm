<?php
namespace Bixev\ORM;

class Check
{

    /**
     * tests all the record classes given in param
     *
     * @param array $classes
     * @return array ('title'=>,'result'=>,'errMsg'=>)
     */
    public function checkAll(array $classes = [])
    {
        $return = [];
        // self to test from parent:: the good ones
        foreach ($classes as $recordClass) {
            $return[] = $this->checkOne($recordClass);
        }

        return $return;
    }

    /**
     *
     * @param string $title
     * @param bool $result
     * @param string $errMsg
     * @return array
     */
    protected function getCheckResult($title, $result, $errMsg = '')
    {
        return [
            'title'  => $title,
            'result' => $result,
            'errMsg' => $errMsg,
        ];
    }

    /**
     * @param AbstractModel $recordClass
     * @return array
     */
    protected function checkOne($recordClass)
    {
        if (!class_exists($recordClass)) {
            return $this->getCheckResult($recordClass, false, 'class not found');
        }
        if (!is_subclass_of($recordClass, '\Bixev\ORM\Record')) {
            return $this->getCheckResult($recordClass, false, 'class is NOT a subclassof \Bixev\ORM\Record');
        }

        $classErrors = $this->checkFieldList($recordClass);
        foreach ($classErrors as $error) {
            return $this->getCheckResult($recordClass, false, $error);
        }

        $databaseName = $recordClass::getDatabaseName();
        $tableName = $recordClass::getTableName();
        if (!$this->tableExists($databaseName, $tableName)) {
            return $this->getCheckResult($recordClass, false, 'table ' . $tableName . ' does NOT exist in database ' . $databaseName);
        }

        $fields = $recordClass::getFieldList();

        $nonExistingFields = [];
        foreach ($fields as $fieldName => $fieldInfos) {
            if (!$this->fieldExists($databaseName, $tableName, $fieldName)) {
                $nonExistingFields[] = $fieldName;
            }
        }

        if (count($nonExistingFields) != 0) {
            return $this->getCheckResult($recordClass, false, 'fields do NOT exist : ' . implode(',', $nonExistingFields));
        }

        try {
            $object = new $recordClass;
            /* @var $object AbstractModel */
            $object->getId();
        } catch (\Exception $e) {
            return $this->getCheckResult($recordClass, false, 'Error while testing new object : ' . $e->getMessage());
        }

        return $this->getCheckResult($recordClass, true);
    }

    /**
     *
     * @param string $databaseName
     * @param string $tableName
     * @return bool
     */
    protected function tableExists($databaseName, $tableName)
    {
        try {
            $rows = Database::get($databaseName)->query("SHOW TABLES LIKE '" . Database::get($databaseName)->p($tableName) . "'")->fetchAll();

            return count($rows) == 1;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     *
     * @param string $database
     * @param string $tableName
     * @param string $fieldName
     * @return bool
     */
    protected function fieldExists($database, $tableName, $fieldName)
    {
        if (empty($tableName) || empty($fieldName)) {
            return false;
        }
        try {
            $sql = "SHOW COLUMNS FROM " . Database::get($database)->backquote($tableName) . " LIKE " . Database::get($database)->quote($fieldName);
            $fields = Database::get($database)->query($sql)->fetchAll();

            return count($fields) != 0;
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * @param AbstractModel $className
     * @return array
     */
    final protected function checkFieldList(AbstractModel $className)
    {

        $errors = [];

        // on vérifie d'abord que la table est définie
        $tableName = $className::getTableName();
        if ($tableName === null || !is_string($tableName) || strlen($tableName) == 0) {
            $errors[] = "undefined table name";
        }

        $fieldList = $className::getFieldList();
        if ($fieldList === null) {
            $errors[] = "undefined field list";
        } elseif (!is_array($fieldList)) {
            $errors[] = "field list is not an array";
        } elseif (!count($fieldList)) {
            $errors[] = "empty field list";
        } else {
            foreach ($fieldList as $fieldName => $fieldInfos) {
                if (!is_string($fieldName) || strlen($fieldName) == 0) {
                    $errors[] = "undefined field name in field list";
                } else {
                    if (!isset($fieldInfos['type'])) {
                        $errors[] = "undefined field type for field named " . $fieldName;
                    } elseif (!in_array($fieldInfos['type'], AbstractModel::getFieldTypes())) {
                        $errors[] = "unknown field type for field named " . $fieldName . " : " . $fieldInfos['type'];
                    }
                    if (!isset($fieldInfos['required'])) {
                        $fieldList[$fieldName]['required'] = false;
                    } elseif (!is_bool($fieldInfos['required'])) {
                        $errors[] = "invalid required value for field named " . $fieldName . " : " . $fieldInfos['required'];
                    }
                    if (!isset($fieldInfos['size'])) {
                        $fieldList[$fieldName]['size'] = 0;
                    } elseif ($fieldInfos['type'] == 'float' && preg_match('#[0-9]+,[0-9]+#', $fieldInfos['size']) === false) {
                        $errors[] = "invalid size for field named " . $fieldName . " (" . $fieldInfos['type'] . ") : " . $fieldInfos['size'];
                    } elseif ($fieldInfos['type'] != 'float' && !is_int($fieldInfos['size'])) {
                        $errors[] = "size must be integer for field named " . $fieldName . " (" . $fieldInfos['type'] . ") : " . $fieldInfos['size'];
                    }
                }
            }
        }

        $relationList = $className::getRelationList();
        if ($relationList === null) {
            $errors[] = "undefined relation list";
        } elseif (!is_array($relationList)) {
            $errors[] = "relation list is not an array";
        } elseif (!count($relationList)) {
            $errors[] = "empty relation list";
        } else {
            foreach ($relationList as $relation) {
                if (!isset($relation['field'])) {
                    $errors[] = "missing field on relation";
                }
                if (!isset($relation['class'])) {
                    $errors[] = "missing class on relation";
                }
                if (!isset($fieldList[$relation['field']])) {
                    $errors[] = "unknown field name in relation " . $relation['field'];
                }
                if (!is_subclass_of($relation['class'], __CLASS__)) {
                    $errors[] = "relation is not a model " . $relation['class'];
                }
            }
        }

        return $errors;
    }

}
