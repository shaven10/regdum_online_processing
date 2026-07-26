<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/clearance.php';

ensureClearanceSchema();
echo "Cashier and Registrar removed from online clearance.\n";
