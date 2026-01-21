<?php
namespace Ps_ProGate\Infra;

final class PhpRedirector implements RedirectorInterface
{
    public function redirectAndExit(string $target): void
    {
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $target, true, 302);
        exit;
    }
}
