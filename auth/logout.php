<?php
require_once __DIR__ . '/../helpers.php';
start_session();
session_destroy();
header("Location: " . rtrim(BASE_URL,'/') . "/index.php");
exit;
