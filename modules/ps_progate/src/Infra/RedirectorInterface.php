<?php
namespace Ps_ProGate\Infra;

interface RedirectorInterface
{
    /** @return never */
    public function redirectAndExit(string $target): void;
}
