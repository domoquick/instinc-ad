<?php
declare(strict_types=1);

// IMPORTANT: keep this bootstrap silent (no echo/print). Use error_log() for debug.// NO ECHO


// 1) vendor du core PrestaShop (si présent)
$coreAutoload = dirname(__DIR__, 3) . '/autoload.php';
if (file_exists($coreAutoload)) {

    require $coreAutoload;
}

// 2) vendor du module (si tu en as un)
$moduleAutoload = dirname(__DIR__, 1) . '/vendor/autoload.php';
if (file_exists($moduleAutoload)) {

    require $moduleAutoload;
}

// 3) charge la classe legacy du module (Ps_progate) pour accéder aux constantes si besoin
$mainModuleFile = dirname(__DIR__) . '/ps_progate.php';
if (file_exists($mainModuleFile)) {

    require_once $mainModuleFile;
}
