<?php
/**
 * Copy this file to config/db.local.php on the server and fill in the values
 * from your hosting control panel (MySQL Databases section).
 * db.local.php is read automatically and is never overwritten by updates.
 * Keep it out of any public backup or repository.
 */
define('DB_HOST', 'localhost');           // usually "localhost"; some hosts give you a specific hostname
define('DB_USER', 'cpanel_dbuser');       // the database USER you created
define('DB_PASS', 'a-long-random-password');
define('DB_NAME', 'cpanel_institute');    // the database NAME (often prefixed with your account name)

// define('APP_ENV', 'production');       // leave as production on a live site
// define('APP_TIMEZONE', 'Asia/Baghdad'); // change only if the institute is outside Iraq/Kurdistan
