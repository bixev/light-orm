<?php
// Individuel
$user = new \Bixev\ORM\User();
$user->name = "Tom";
$user->save();

$user = new \ORM\User(3);
echo $user->name;

$row = \PDO::query("SELECT id, name FROM user")->fetch();
$user = new \ORM\User($row);

// via l'api
$user = \ORM\API::get('\ORM\User')->findOneBy(array('name' => "Tom"));

// via API (collection)
$users = \ORM\API::get('\ORM\User')->findBy(array('name' => "Tom"));
foreach ($users as $user) {
    //...
}
