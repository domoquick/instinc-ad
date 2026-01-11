<?php

namespace ProPrivate\EventSubscriber;

use Configuration;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\KernelEvents;

class FrontAccessSubscriber implements EventSubscriberInterface
{
    private $context;
    private $router;

    public function __construct($context, $router)
    {
        $this->context = $context; // prestashop.adapter.legacy.context
        $this->router = $router;   // router
    }

    public static function getSubscribedEvents(): array
    {
        return [
            KernelEvents::REQUEST => ['onKernelRequest', 5],
        ];
    }

    public function onKernelRequest(RequestEvent $event): void
    {
        if (!$event->isMainRequest()) {
            return;
        }

        $request = $event->getRequest();

        $shopId = (int) $this->context->shop->id;

        // --- Configuration (par boutique) ---
        $enabled = (bool) Configuration::get('PROPRIVATE_ENABLED', null, null, $shopId);
        if (!$enabled) {
            return;
        }

        $shopIdsCsv = (string) Configuration::get('PROPRIVATE_SHOP_IDS', '', null, $shopId);
        if (!$this->isShopAllowed($shopId, $shopIdsCsv)) {
            return;
        }

        $hostsMultiline = (string) Configuration::get('PROPRIVATE_HOSTS', '', null, $shopId);
        if (!$this->isHostAllowed($request->getHost(), $hostsMultiline)) {
            return;
        }

        $allowedPathsMultiline = (string) Configuration::get('PROPRIVATE_ALLOWED_PATHS', '', null, $shopId);
        $pathInfo = rtrim($request->getPathInfo(), '/') ?: '/';
        if ($this->isPathAllowed($pathInfo, $allowedPathsMultiline)) {
            return;
        }

        // Si connecté, vérifier "rôle" (groupes)
        if ($this->context->customer->isLogged()) {
            $allowedGroupsCsv = (string) Configuration::get('PROPRIVATE_ALLOWED_GROUPS', '', null, $shopId);

            // Par sécurité: si aucun groupe configuré, on autorise les connectés
            if (trim($allowedGroupsCsv) === '') {
                return;
            }

            if ($this->isCustomerInAllowedGroups($allowedGroupsCsv)) {
                return;
            }

            // Connecté mais pas dans le(s) groupe(s) autorisé(s)
            $event->setResponse($this->forbiddenResponse());
            return;
        }

        // Non connecté: bots en 403, humains redirect ou 403
        $bots403 = (bool) Configuration::get('PROPRIVATE_BOTS_403', 1, null, $shopId);
        $humansRedirect = (bool) Configuration::get('PROPRIVATE_HUMANS_REDIRECT', 1, null, $shopId);

        if ($bots403 && $this->looksLikeBot((string) $request->headers->get('User-Agent', ''))) {
            $event->setResponse($this->forbiddenResponse());
            return;
        }

        if ($humansRedirect) {
            $loginUrl = $this->router->generate('authentication');
            $event->setResponse(new RedirectResponse($loginUrl));
            return;
        }

        $event->setResponse($this->forbiddenResponse());
    }

    private function forbiddenResponse(): Response
    {
        $response = new Response('Forbidden', 403);
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        return $response;
    }

    private function isShopAllowed(int $shopId, string $csv): bool
    {
        $csv = trim($csv);
        if ($csv === '') {
            return true; // vide = toutes
        }
        $ids = array_filter(array_map('intval', preg_split('/\s*,\s*/', $csv)));
        return in_array($shopId, $ids, true);
    }

    private function isHostAllowed(string $host, string $hostsMultiline): bool
    {
        $hostsMultiline = trim($hostsMultiline);
        if ($hostsMultiline === '') {
            return true; // vide = tous
        }
        $allowed = array_filter(array_map('trim', preg_split('/\R+/', $hostsMultiline)));
        $hostLower = mb_strtolower($host);
        foreach ($allowed as $a) {
            if ($hostLower === mb_strtolower($a)) {
                return true;
            }
        }
        return false;
    }

    private function isPathAllowed(string $pathInfo, string $pathsMultiline): bool
    {
        $pathsMultiline = (string) $pathsMultiline;
        $lines = array_filter(array_map('trim', preg_split('/\R+/', $pathsMultiline)));

        foreach ($lines as $pattern) {
            if ($pattern === '') {
                continue;
            }
            // wildcard: /module/* -> regex
            $pattern = rtrim($pattern, '/');
            $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '/?$#';
            if (preg_match($regex, $pathInfo)) {
                return true;
            }
        }
        return false;
    }

    private function isCustomerInAllowedGroups(string $csv): bool
    {
        $allowed = array_filter(array_map('intval', preg_split('/\s*,\s*/', trim($csv))));
        if (!$allowed) {
            return false;
        }

        $customerGroups = (array) $this->context->customer->getGroups();
        foreach ($customerGroups as $gid) {
            if (in_array((int) $gid, $allowed, true)) {
                return true;
            }
        }
        return false;
    }

    private function looksLikeBot(string $ua): bool
    {
        $ua = mb_strtolower(trim($ua));
        if ($ua === '') {
            return true;
        }

        $needles = [
            'bot', 'spider', 'crawler', 'slurp',
            'bingpreview', 'duckduckbot', 'baiduspider', 'yandex',
            'facebookexternalhit', 'ahrefs', 'semrush', 'mj12bot',
            'screaming frog',
        ];

        foreach ($needles as $n) {
            if (strpos($ua, $n) !== false) {
                return true;
            }
        }

        return false;
    }
}
