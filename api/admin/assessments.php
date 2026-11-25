<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/admin_guard.php';
$assessmentActor = $currentUser;
$assessmentAdmin = true;
require __DIR__ . '/../assessment/management.php';
