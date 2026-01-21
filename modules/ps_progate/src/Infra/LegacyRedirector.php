<?php
declare(strict_types=1);

namespace Ps_ProGate\Infra;

use RuntimeException;
use Tools;

final class LegacyRedirector implements RedirectorInterface
{
    public function redirect(string $url, int $status = 302): never
    {
        if (headers_sent()) {
            throw new RuntimeException('Cannot redirect, headers already sent.');
        }

        Tools::redirect($url, $status);
        exit;
    }

    public function redirectToPath(string $path): never
    {
        $this->redirect($path, 302);
    }

    public function redirectAndExit(string $target): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $target, true, 302);
        exit;
    }
}
