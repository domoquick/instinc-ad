<?php
namespace Ps_ProGate\Tests\Support;

use Ps_ProGate\Infra\RedirectorInterface;

final class TestRedirector implements RedirectorInterface
{
    public ?string $lastTarget = null;

    public function redirectAndExit(string $target): void
    {
        $this->lastTarget = $target;
        // En unit test on ne veut PAS exit, donc on stoppe via exception contrôlée
        throw new \RuntimeException('REDIRECT:' . $target);
    }
}
