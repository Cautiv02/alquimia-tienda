<?php
if (session_status() === PHP_SESSION_NONE) { session_name('alq_admin'); session_start(); }
$_SESSION = [];
session_destroy();
header('Location: login.php');
exit;
