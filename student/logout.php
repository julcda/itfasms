<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/student_auth.php';

student_logout();
flash_set('success', 'You have been logged out.');
redirect_to(app_url('student/login.php'));
