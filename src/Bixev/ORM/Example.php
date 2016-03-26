<?php
namespace Bixev\ORM;

/**
 *
 * table : example
 *
 * @property int $id
 * @property string $name
 */
class Example extends AbstractModel
{

    /**
     * @var string
     */
    static protected $table = 'users';

    /**
     * @var array
     */
    static protected $fieldList = array(
        'id' => array('type' => 'int', 'size' => 10, 'required' => true),
        'name' => array('type' => 'str', 'size' => 64, 'required' => false),
    );

}
