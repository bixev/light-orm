<?php
namespace Bixev\ORM;

class Collection implements \ArrayAccess, \Countable, \Iterator
{

    /**
     * @var array
     */
    protected $_content = [];

    /**
     * @var int
     */
    protected $_iteratorPosition = 0;

    /**
     * @var string
     */
    protected $_storeMethod;

    const STORE_METHOD_OBJECT = 1;
    const STORE_METHOD_ROW = 2;
    const STORE_METHOD_ID = 3;
    const STORE_METHOD_DEFAULT = 1;

    static protected $_allowedStoreMethods = [1, 2, 3];

    /**
     * @var AbstractModel
     */
    protected $_className;

    /**
     * @param string $className
     * @param string $storeMethod
     * @throws \Exception
     */
    public function __construct($className, $storeMethod = null)
    {
        $this->setClass($className);
        $this->setStoreMethod($storeMethod);
    }

    //###############################//
    //#####     ArrayAccess     #####//
    public function offsetExists($offset)
    {
        return isset($this->_content[$offset]);
    }

    public function offsetGet($offset)
    {
        if (!isset($this->_content[$offset])) {
            return null;
        }

        return $this->convertFromStore($this->_content[$offset]);
    }

    public function offsetSet($offset, $value)
    {
        $value = $this->getRecordObject($value);
        if (!($value instanceof $this->_className)) {
            throw new Exception(__METHOD__ . ' : Value is not instanceof : ' . $this->_className);
        }
        if (!$value->isValid()) {
            throw new Exception(__METHOD__ . ' : Value is not a valid instance of : ' . $this->_className);
        }

        $value = $this->convertToStore($value);

        if (!$this->contains($value) || $this->search($value) === $offset) {
            if ($offset === null) {
                $this->_content[] = $value;
            } else {
                $this->_content[$offset] = $value;
            }
        } elseif ($offset !== null) {
            throw new \Exception(__METHOD__ . ' : offset given and element already in collection');
        }
    }

    public function offsetUnset($offset)
    {
        if (isset($this->_content[$offset])) {
            unset($this->_content[$offset]);
            if ($this->_iteratorPosition > 0) {
                $this->_iteratorPosition--;
            }
        }
    }
    //#####   end ArrayAccess   #####//
    //###############################//
    //#############################//
    //#####     Countable     #####//
    public function count()
    {
        return count($this->_content);
    }
    //#####   end Countable   #####//
    //#############################//
    //############################//
    //#####     Iterator     #####//
    public function rewind()
    {
        $this->_iteratorPosition = 0;
    }

    public function current()
    {
        return $this->convertFromStore($this->_content[$this->_iteratorPosition]);
    }

    public function key()
    {
        return $this->_iteratorPosition;
    }

    public function next()
    {
        ++$this->_iteratorPosition;
    }

    public function valid()
    {
        return isset($this->_content[$this->_iteratorPosition]);
    }
    //#####     end Iterator     #####//
    //################################//

    /**
     * Checks whether the collection contains a specific key/index.
     *
     * @param mixed $key The key to check for.
     * @return boolean true if the given key/index exists, false otherwise.
     */
    public function containsKey($key)
    {
        return isset($this->_content[$key]);
    }

    /**
     * Checks whether the given element is contained in the collection.
     * Only element values are compared, not keys. The comparison of two elements
     * is strict, that means not only the value but also the type must match.
     * For objects this means reference equality.
     *
     * @param AbstractModel $value
     * @return boolean true if the given element is contained in the collection,
     *          false otherwise.
     */
    public function contains($value)
    {
        if (!$this->isObjectValid($value)) {
            return false;
        }

        if ('\\' . get_class($value) != $this->_className) {
            return false;
        }
        $value = $this->convertToStore($value, static::STORE_METHOD_ROW);

        foreach ($this->_content as $elt) {
            if ($value === $this->convertToStore($elt, static::STORE_METHOD_ROW)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Checks whether the given element is contained in the collection.
     * Only element values are compared, not keys. The comparison of two elements
     * is strict, that means not only the value but also the type must match.
     * For objects this means reference equality.
     *
     * @param AbstractModel $value
     * @return boolean true if the given element is contained in the collection,
     *          false otherwise.
     */
    public function containsById($value)
    {
        if (!$this->isObjectValid($value)) {
            return false;
        }

        if ('\\' . get_class($value) != $this->_className) {
            return false;
        }

        return $this->containsId($value->getId());
    }

    /**
     * Checks whether the given element id is contained in the collection.
     *
     * @param int $id
     * @return boolean true if the given element is contained in the collection,
     *          false otherwise.
     */
    public function containsId($id)
    {
        foreach ($this as $elt) {
            if ($id == $elt->getId()) {
                return true;
            }
        }

        return false;
    }

    /**
     * return the ids of the collection
     *
     * @return int[]
     */
    public function getIds()
    {
        $ids = [];
        foreach ($this as $elt) {
            $ids[] = $elt->getId();
        }

        return $ids;
    }

    /**
     * @param AbstractModel $value
     * @return int offset, false if not found
     */
    public function search($value)
    {
        if (!$this->isObjectValid($value)) {
            return false;
        }

        $value = $this->convertToStore($value);

        foreach ($this->_content as $k => $elt) {
            if ($value === $elt) {
                return $k;
            }
        }

        return false;
    }

    /**
     * Searches for a given element and, if found, returns the corresponding key/index
     * of that element. The comparison of two elements is strict, that means not
     * only the value but also the type must match.
     * For objects this means reference equality.
     *
     * @param AbstractModel $element The element to search for.
     * @return mixed The key/index of the element or false if the element was not found.
     */
    public function indexOf($element)
    {
        $element = $this->getRecordObject($element);
        if (!$this->isObjectValid($element)) {
            return false;
        }

        $value = $this->convertToStore($element);


        foreach ($this->_content as $key => $elt) {
            if ($value === $elt) {
                return $key;
            }
        }

        return false;
    }

    /**
     * Extract a slice of $length elements starting at position $offset from the Collection.
     *
     * If $length is null it returns all elements from $offset to the end of the Collection.
     * Keys have to be preserved by this method. Calling this method will only return the
     * selected slice and NOT change the elements contained in the collection slice is called on.
     *
     * @param int $offset
     * @param int $length
     * @return array
     */
    public function slice($offset, $length = null)
    {
        return array_slice($this->_content, $offset, $length, true);
    }

    /**
     * @param AbstractModel $value
     * @return bool
     */
    public function isObjectValid($value)
    {
        $value = $this->getRecordObject($value);

        return $value->isValid();
    }

    public function usort($callback)
    {
        usort($this->_content, $callback);
    }

    /**
     * Ordonne la collection selon un champ de Record
     *
     * @param string $fieldName
     * @param string $order ASC|DESC
     * @throws \Exception
     */
    public function orderBy($fieldName, $order = 'ASC')
    {
        if ($this->_storeMethod != static::STORE_METHOD_OBJECT && $this->_storeMethod != static::STORE_METHOD_ROW) {
            throw new \Exception(
                __METHOD__ . " : Collection doesn't use objects or row - unable to order by field " . $fieldName
            );
        }

        $class = $this->_className;
        $infos = $class::getFieldInfos($fieldName);
        $fieldType = $infos['type'];
        $storeMethod = $this->_storeMethod;

        usort(
            $this->_content,
            function ($a, $b) use ($fieldName, $fieldType, $order, $storeMethod) {
                if ($storeMethod == static::STORE_METHOD_OBJECT) {
                    $valueA = $a->$fieldName;
                    $valueB = $b->$fieldName;
                } else {
                    $valueA = $a[$fieldName];
                    $valueB = $b[$fieldName];
                }
                //'int','bool','str','float','date','dateTime'
                switch ($fieldType) {
                    case'int':
                    case'float':
                    case'bool':
                        if ($valueA > $valueB) {
                            $return = 1;
                        } else {
                            $return = -1;
                        }
                        break;
                    case 'str':
                        $return = strcmp($valueA, $valueB);
                        break;
                    case'date':
                        // compare timestamps
                        preg_match('!([0-9]+)-([0-9]+)-([0-9]+)!si', $valueA, $aMatches);
                        preg_match('!([0-9]+)-([0-9]+)-([0-9]+)!si', $valueB, $bMatches);
                        $d = mktime(0, 0, 0, $aMatches[2], $aMatches[1], $aMatches[3]) - mktime(
                                0,
                                0,
                                0,
                                $bMatches[2],
                                $bMatches[1],
                                $bMatches[3]
                            );
                        if ($d > 0) {
                            $return = 1;
                        } else {
                            $return = -1;
                        }
                        break;
                    case'dateTime':
                        // compare timestamps
                        preg_match('!([0-9]+)-([0-9]+)-([0-9]+) ([0-9]+):([0-9]+):([0-9]+)!si', $valueA, $aMatches);
                        preg_match('!([0-9]+)-([0-9]+)-([0-9]+) ([0-9]+):([0-9]+):([0-9]+)!si', $valueB, $bMatches);
                        $d = mktime(
                                $aMatches[4],
                                $aMatches[5],
                                $aMatches[6],
                                $aMatches[2],
                                $aMatches[1],
                                $aMatches[3]
                            ) - mktime(
                                $bMatches[4],
                                $bMatches[5],
                                $bMatches[6],
                                $bMatches[2],
                                $bMatches[1],
                                $bMatches[3]
                            );
                        if ($d > 0) {
                            $return = 1;
                        } else {
                            $return = -1;
                        }
                        break;
                    default:
                        throw new \Exception(__METHOD__ . ' : unknown fieldType : ' . $fieldType);
                        break;
                }

                // inverse order ?
                if ($order == 'DESC') {
                    $return = -$return;
                }

                return $return;
            }
        );
    }

    /**
     * @param array $row
     */
    public function addByRow($row)
    {
        $class = $this->_className;
        $this[] = new $class('row', $row);
    }

    /**
     * @param int $id
     */
    public function addById($id)
    {
        $class = $this->_className;
        $this[] = new $class('id', $id);
    }

    /**
     * @param int $storeMethod
     * @return bool
     */
    protected function isStoreMethodValid($storeMethod)
    {
        return (array_search($storeMethod, static::$_allowedStoreMethods) !== false);
    }

    /**
     * @param null $storeMethod
     * @throws \Exception
     */
    public function setStoreMethod($storeMethod = null)
    {
        $storeMethod = $storeMethod ? $storeMethod : static::STORE_METHOD_DEFAULT;

        if (!$this->isStoreMethodValid($storeMethod)) {
            throw new Exception(__METHOD__ . ' : Unknown storeMethod : ' . $storeMethod);
        }


        if ($storeMethod == $this->_storeMethod) {
            return;
        } elseif ($storeMethod == static::STORE_METHOD_OBJECT) {
            foreach ($this->_content as $key => $elt) {
                $this->_content[$key] = $this->convertFromStore($elt);
            }
        } elseif ($storeMethod == static::STORE_METHOD_ROW) {
            foreach ($this->_content as $key => $elt) {
                $this->_content[$key] = $this->convertFromStore($elt)->getRow();
            }
        } else {
            foreach ($this->_content as $key => $elt) {
                $this->_content[$key] = $this->convertFromStore($elt)->getId();
            }
        }

        $this->_storeMethod = $storeMethod;
    }

    protected function setClass($class)
    {
        if (!class_exists($class)) {
            throw new \Exception(__METHOD__ . ' : Unknown model className : ' . $class);
        }
        $this->_className = $class;
    }

    /**
     * @param $value
     * @return bool
     * @throws \Exception
     */
    public function remove($value)
    {
        $value = $this->getRecordObject($value);
        if (!$this->isObjectValid($value)) {
            return false;
        }

        $value = $this->convertToStore($value);

        foreach ($this->_content as $key => $elt) {
            if ($value === $elt) {
                unset($this->_content[$key]);
                if ($this->_iteratorPosition > 0) {
                    $this->_iteratorPosition--;
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @return AbstractModel
     */
    public function getModelClass()
    {
        return $this->_className;
    }

    /**
     * @param Collection $collection
     * @throws \Exception
     */
    public function merge($collection)
    {
        if (get_class($collection) != __CLASS__ || $this->getModelClass() != $collection->getModelClass()) {
            throw new \Exception(__METHOD__ . " : Collection to merge does not match");
        }

        foreach ($collection as $elt) {
            $this[] = $elt;
        }
    }

    /**
     * @param Collection $collection
     * @return array|static
     * @throws \Exception
     */
    public function diff($collection)
    {
        if (get_class($collection) != __CLASS__ || $this->getModelClass() != $collection->getModelClass()) {
            throw new \Exception(__METHOD__ . " : Collection to merge does not match");
        }

        $newCollection = new static($this->_className, $this->_storeMethod);
        foreach ($this as $elt) {
            if (!$collection->contains($elt)) {
                $newCollection[] = $elt;
            }
        }

        return $newCollection;
    }

    /**
     * get the last element
     *
     * @return AbstractModel
     */
    public function pop()
    {
        $elt = array_pop($this->_content);

        return $this->convertFromStore($elt);
    }

    /**
     * get the first element
     *
     * @return AbstractModel
     */
    public function shift()
    {
        $elt = array_shift($this->_content);

        return $this->convertFromStore($elt);
    }

    /**
     * @param AbstractModel $storedObj
     * @param string $storeMethod
     * @return AbstractModel
     * @throws \Exception
     */
    protected function convertFromStore($storedObj = null, $storeMethod = null)
    {
        $storeMethod = $storeMethod ? $storeMethod : $this->_storeMethod;

        if (!$this->isStoreMethodValid($storeMethod)) {
            throw new Exception(__METHOD__ . ' : Unknown storeMethod : ' . $storeMethod);
        }

        if ($storedObj === null) {
            return null;
        } elseif ($storeMethod == static::STORE_METHOD_OBJECT) {
            return $storedObj;
        } elseif ($storeMethod == static::STORE_METHOD_ROW) {
            $class = $this->_className;

            return new $class('row', $storedObj);
        } else {
            $class = $this->_className;

            return new $class('id', $storedObj);
        }
    }

    /**
     * @param null $obj
     * @param null $storeMethod
     * @return array|AbstractModel|int|null
     * @throws \Exception
     */
    protected function convertToStore($obj = null, $storeMethod = null)
    {
        $storeMethod = $storeMethod ? $storeMethod : $this->_storeMethod;

        if (!$this->isStoreMethodValid($storeMethod)) {
            throw new Exception(__METHOD__ . ' : Unknown storeMethod : ' . $storeMethod);
        }

        if ($obj === null) {
            return null;
        } else {
            $obj = $this->getRecordObject($obj);
            if ($storeMethod == static::STORE_METHOD_OBJECT) {
                return $obj;
            } elseif ($storeMethod == static::STORE_METHOD_ROW) {
                return $obj->getRow();
            } else {
                return $obj->getId();
            }
        }
    }

    /**
     * @param int|array|AbstractModel $obj
     * @return AbstractModel
     */
    protected function getRecordObject($obj = null)
    {
        $class = $this->_className;
        if ($obj === null) {
            $obj = null;
        } elseif (is_int($obj)) {
            $obj = new $class('id', $obj);
        } elseif (is_array($obj)) {
            $obj = new $class('row', $obj);
        } elseif ($obj instanceof $class) {

        } else {
            $obj = null;
        }

        return $obj;
    }
}
