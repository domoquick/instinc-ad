<?php
declare(strict_types=1);

namespace Ps_ProGate\Tests\Unit\EventSubscriber;

use PHPUnit\Framework\TestCase;
use Ps_ProGate\EventSubscriber\FrontAccessSubscriber;
use Ps_ProGate\Service\AccessGateInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;

final class FrontAccessSubscriberTest extends TestCase
{
    public function testSetsResponseWhenGateBlocks(): void
    {
        $gate = $this->createMock(AccessGateInterface::class);
        $gate->method('enforceSymfony')->willReturn(new Response('blocked', 302));

        $subscriber = new FrontAccessSubscriber($gate, $this->createMock(TokenStorageInterface::class));

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/product/1');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertNotNull($event->getResponse());
        $this->assertSame(302, $event->getResponse()->getStatusCode());
    }

    public function testDoesNothingWhenGateAllows(): void
    {
        $gate = $this->createMock(AccessGateInterface::class);
        $gate->method('enforceSymfony')->willReturn(null);

        $subscriber = new FrontAccessSubscriber($gate, $this->createMock(TokenStorageInterface::class));

        $kernel = $this->createMock(HttpKernelInterface::class);
        $request = Request::create('/authentication');

        $event = new RequestEvent($kernel, $request, HttpKernelInterface::MAIN_REQUEST);

        $subscriber->onKernelRequest($event);

        $this->assertNull($event->getResponse());
    }
}
