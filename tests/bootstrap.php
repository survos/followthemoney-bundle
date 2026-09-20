<?php
declare(strict_types=1);
$mono = dirname(__DIR__, 3).'/vendor/autoload.php';
require is_file($mono) ? $mono : dirname(__DIR__).'/vendor/autoload.php';
