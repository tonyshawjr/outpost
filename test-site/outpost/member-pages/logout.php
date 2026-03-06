<?php
/**
 * Outpost CMS — Member Logout
 */
require_once dirname(__DIR__) . '/members.php';

OutpostMember::logout();
header('Location: /');
exit;
