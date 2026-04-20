<?php
// public/index.php
// ALL requests go through here.

require_once dirname(__DIR__) . '/bootstrap/app.php';

$router = require dirname(__DIR__) . '/routes/api.php';
$router->dispatch();
