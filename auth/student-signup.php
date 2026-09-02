<?php
require_once __DIR__ . '/../config/app.php';
redirect(APP_URL . '/auth/login.php?mode=student');
