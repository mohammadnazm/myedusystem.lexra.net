<?php
require __DIR__ . '/config/db.php';
require __DIR__ . '/includes/auth.php';
logout();
header('Location: ' . base_url('index.php'));
exit;
