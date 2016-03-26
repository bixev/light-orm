<?php
$exampleRepository = \Bixev\ORM\API::get('Example');

// create record
$example = $exampleRepository->createNew();
// same as
// $example = new \Bixev\ORM\Example();
$example->example_2_id = 5;
$example->name = "Tom";
$example->save();

// get existing record by id
$id = 3;
$example = $exampleRepository->find($id);
// same as
// $example = new \Bixev\ORM\Example($id);
echo $example->name;

// get existing record by row
$db = new \PDO('');
$row = $db->query("SELECT id, name, example_2_id FROM user")->fetch();
// row has to contain all declared fields
$user = new \Bixev\ORM\Example($row);

// search
$examples = $exampleRepository->findBy(['name' => "Tom"]);
$examples = $exampleRepository->findAll();
foreach ($examples as $example) {
    echo $example->name;
}

// search from sql query
$db = new \PDO('');
$rows = $db->query("SELECT id, name, example_2_id FROM user")->fetchAll();
// rows have to contain all declared fields
$examples = $exampleRepository->newCollection($rows);

// find one
$example = $exampleRepository->findOneBy(['name' => "Tom"]);
