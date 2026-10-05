<?php
require __DIR__ . '/../inc/bootstrap.php';
$_SESSION = [];
session_destroy();
redirect(base_url('admin/login.php'));
