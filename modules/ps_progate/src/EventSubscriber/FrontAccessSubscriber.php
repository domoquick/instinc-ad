<?php
declare(strict_types=1);

namespace Ps_ProGate\EventSubscriber;

use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

use Ps_ProGate\Service\AccessGateInterface;

final class FrontAccessSubscriber implements EventSubscriberInterface
{
    private AccessGateInterface $accessGate;
    private ?TokenStorageInterface $tokenStorage;

    public function __construct(AccessGateInterface $accessGate, ?TokenStorageInterface $tokenStorage = null)
    {
        $this->accessGate = $accessGate;
        $this->tokenStorage = $tokenStorage;
    }

    public static function getSubscribedEvents(): array
    {
        // Early enough to redirect before controller execution
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 8],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        // Avoid BO / API
        $path = $request->getPathInfo();
        if (strpos($path, '/admin') === 0 || strpos($path, '/api') === 0) {
            return;
        }

        $response = $this->accessGate->enforceSymfony($request);
        if ($response) {
            $event->setResponse($response);
        }
    }
}
