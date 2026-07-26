<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/compliance.php';

ensureRequirementDefaultsSchema();
echo "Requirement settings migration completed.\n";
