<?php
declare(strict_types=1);

namespace Ps_ProGate\Service;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

interface AccessGateInterface
{
    public function enforceLegacy(): void;

    /**
     * @return Response|null
     */
    public function enforceSymfony(Request $request): ?Response;
}
