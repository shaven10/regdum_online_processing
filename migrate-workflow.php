<?php
/**
 * Run once to apply the four-step workflow migration.
 * Delete this file after running in production.
 */
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/compliance.php';

ensureWorkflowSchema();
echo "Workflow migration completed.\n";
