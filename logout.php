<?php
/**
 * logout.php — Destroys user session and redirects to login.
 */
require_once __DIR__ . '/includes/auth.php';
logout();
header('Location: login.php');
exit;
