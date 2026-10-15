<?php
require __DIR__ . '/../config/db.php';
require __DIR__ . '/../includes/auth.php';
require __DIR__ . '/../includes/functions.php';
require_role('admin');
$role = 'admin';
require __DIR__ . '/../includes/payments_overview_page.php';
