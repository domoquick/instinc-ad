<?php
declare(strict_types=1);

namespace Ps_ProGate\Infra;

interface ServerBagInterface
{
    public function getHost(): string;       // HTTP_HOST sans port
    public function getUserAgent(): string;  // HTTP_USER_AGENT
    public function getRequestUri(): string; // REQUEST_URI
    public function getRemoteAddr(): string;
}
