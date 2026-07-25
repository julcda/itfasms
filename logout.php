<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

logout_user();
flash_set('success', 'You have been logged out successfully.');
redirect_to(app_url('login.php'));
