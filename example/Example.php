<?php
namespace Bixev\ORM;

/**
 *
 * table : example
 *
 * @property int $id
 * @property int $example_2_id
 * @property string $name
 * @property Example2 $example_2
 */
class Example extends AbstractModel
{

    /**
     * @var string
     */
    static protected $table = 'users';

    /**
     * available fields
     * array of 'FIELDNAME' => ['type'=>'TYPE', size=>'SIZE']
     * type : bool | int | string | float | date | dateTime | time
     * size : integer (0 for no limit), if type=float size is string "int,int"
     *
     * @var array
     */
    static protected $fieldList = [
        'id'           => ['type' => 'int', 'size' => 10, 'required' => true],
        'example_2_id' => ['type' => 'int', 'size' => 20, 'required' => false],
        'name'         => ['type' => 'string', 'size' => 64, 'required' => false],
    ];

    static protected $relationList = [
        'example_2' => ['field' => 'example_2_id', 'class' => 'Example2'],
    ];

}
