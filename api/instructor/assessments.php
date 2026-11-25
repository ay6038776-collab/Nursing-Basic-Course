<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/instructor_guard.php';
$assessmentActor = $currentInstructor;
$assessmentAdmin = in_array('admin', $currentInstructor['roles'] ?? [], true);
require __DIR__ . '/../assessment/management.php';
