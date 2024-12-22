<?php
require_once '../lib/handlers/DatabaseHandler.php';
require_once '../lib/handlers/CRUDHandler.php';
require_once '../lib/handlers/PasswordResetHandler.php';

$dbHandler = new DatabaseHandler("localhost", "root", "", "your_database");
$dbConnection = $dbHandler->getConnection();

$crud = new CRUDHandler($dbConnection);
$passwordReset = new PasswordResetHandler($dbConnection);

// Example usage: Create record
$data = ['name' => 'Example', 'email' => 'example@example.com'];
$crud->create('users', $data);
?>
