This ORM aims to manipulate objects from database the simpliest way 

# Installation

It's recommended that you use Composer to install InterventionSDK.

```bash
composer require bixev/orm "~1.0"
```

This will install this library and all required dependencies.

so each of your php scripts need to require composer autoload file

```php
<?php

require 'vendor/autoload.php';
```

# Usage

## Multiple databases

Initialise database with your own

```php
\Bixev\ORM\Api::setDatabaseGetter(function($databaseName){
    $db = new \PDO('');
    return $db;
});
```

`$databaseName` is used within the models to communicate with correct database

## Repository

Get repository to manipulate objects

```php
$exampleRepository = \Bixev\ORM\Api::get('Example');
```

## Create one object and store it into database

```php
$example = $exampleRepository->createNew();
// same as
// $example = new \Bixev\ORM\Example();
$example->example_2_id = 5;
$example->name = "Tom";
$example->save();
echo $example->getId();
```

## Retrieve existing record by id

```php
$id = 3;
$example = $exampleRepository->find($id);
// same as
// $example = new \Bixev\ORM\Example($id);
echo $example->name;
```

## Retrieve existing record by sql row

```php
$db = new \PDO('');
$row = $db->query("SELECT id, name, example_2_id FROM user")->fetch();
// row has to contain all declared fields
$user = new \Bixev\ORM\Example($row);
```

## Search

```php
$examples = $exampleRepository->findBy(['name' => "Tom"]);
$examples = $exampleRepository->findAll();
foreach ($examples as $example) {
    echo $example->name;
}
```

## Advanced instanciation of collection (by sql rows)

```php
$db = new \PDO('');
$rows = $db->query("SELECT id, name, example_2_id FROM user")->fetchAll();
// rows have to contain all declared fields
$examples = $exampleRepository->newCollection($rows);
```

## Search ONE

```php
$example = $exampleRepository->findOneBy(['name' => "Tom"]);
```
