<?php

declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';

if (is_logged_in()) {
    redirect_to(app_url(user_home_path(current_user())));
}

redirect_to(app_url('login.php'));
