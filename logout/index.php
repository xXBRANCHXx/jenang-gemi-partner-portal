<?php
declare(strict_types=1);

require dirname(__DIR__) . '/partner-auth.php';

jg_partner_logout();

header('Location: ../');
exit;
