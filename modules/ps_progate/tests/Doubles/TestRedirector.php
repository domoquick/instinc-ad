<?php
namespace Ps_ProGate\Tests\Doubles;

use Ps_ProGate\Infra\RedirectorInterface;

final class TestRedirector implements RedirectorInterface
{
    public ?string $lastTarget = null;

    public function redirectAndExit(string $target): void
    {
        $this->lastTarget = $target;
        throw new \RuntimeException('REDIRECT:' . $target);
    }
}
