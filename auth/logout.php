<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../includes/auth.php';
logout();
redirect(APP_URL . '/auth/login.php');
