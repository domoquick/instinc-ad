<?php
declare(strict_types=1);

namespace Ps_ProGate\Service;

use Context;
use Customer;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Ps_ProGate\Config\ConfigKeys;
use Ps_ProGate\Infra\ConfigReaderInterface;
use Ps_ProGate\Infra\ServerBagInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AccessGate implements AccessGateInterface
{
    public function __construct(
        private UrlGeneratorInterface $router,
        private LegacyContext $legacyContext,
        private ConfigReaderInterface $config,
        private ServerBagInterface $server,
        private SearchBotVerifier $botVerifier
    ) {}

    /* -------------------------------------------------------------------------
     * Public API
     * ---------------------------------------------------------------------- */

    public function enforceLegacy(): void
    {
        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return;
        }

        $path = $this->normalizePath($this->server->getRequestUri());

        // 0) Endpoints techniques -> jamais de redirect HTML
        if ($this->isTechnicalRequestLegacy($path)) {
            $this->sendNoContentAndExit();
        }

        // 1) Pages toujours autorisées (anti-boucle / admin / whitelist / assets)
        if ($this->isAlwaysAllowedPath($path)) {
            return;
        }

        $customer = $this->getContext()->customer;

        // 2) Non connecté
        if (!$customer || !$customer->isLogged()) {
            $this->handleUnauthenticatedLegacy($path);
            return;
        }

        // 3) Connecté mais pas autorisé
        if (!$this->isCustomerAllowed($customer)) {
            $this->redirectToPendingLegacy();
            return;
        }
    }

    public function enforceSymfony(Request $request): ?Response
    {
        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return null;
        }

        $path = $this->normalizePath($request->getRequestUri());

        // 0) Endpoints techniques -> jamais de redirect HTML
        if ($this->isTechnicalRequestSymfony($request, $path)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        // 1) Pages toujours autorisées (anti-boucle / admin / whitelist / assets)
        if ($this->isAlwaysAllowedPath($path)) {
            return null;
        }

        $customer = $this->getContext()->customer;

        // 2) Non connecté
        if (!$customer || !$customer->isLogged()) {
            return $this->createUnauthenticatedResponse($request);
        }

        // 3) Connecté mais pas autorisé
        if (!$this->isCustomerAllowed($customer)) {
            return $this->createPendingRedirect();
        }

        return null;
    }

    /* -------------------------------------------------------------------------
     * Core decisions
     * ---------------------------------------------------------------------- */

    private function isAlwaysAllowedPath(string $path): bool
    {
        // pending doit être "hard-allowed"
        if ($this->isOnPendingPage($path)) {
            return true;
        }

        if ($this->isAdminPathAllowed($path)) {
            return true;
        }

        if ($this->isAllowedPath($path)) {
            return true;
        }

        if ($this->isTechnicalAssetAllowed($path)) {
            return true;
        }

        return false;
    }

    private function isTechnicalRequestLegacy(string $path): bool
    {
        // IMPORTANT: on détecte via SERVER + path (legacy)
        return $this->isAjaxLegacy() || $this->isModuleActionEndpoint($path);
    }

    private function isTechnicalRequestSymfony(Request $request, string $path): bool
    {
        return $request->isXmlHttpRequest() || $this->isModuleActionEndpoint($path);
    }

    /* -------------------------------------------------------------------------
     * Gate activation / customers
     * ---------------------------------------------------------------------- */

    public function isGateActiveForCurrentShopAndHost(): bool
    {
        $shopId = $this->getShopId();

        if ((int) $this->config->getInt(ConfigKeys::CFG_ENABLED, $shopId) !== 1) {
            return false;
        }

        // Shop filter
        $targetShopIds = (string) $this->config->getString(ConfigKeys::CFG_SHOP_IDS, $shopId);
        if (trim($targetShopIds) !== '') {
            $shopIds = array_values(array_filter(array_map(
                static fn(string $v): int => (int) trim($v),
                explode(',', $targetShopIds)
            ), static fn(int $v): bool => $v > 0));

            if (!in_array($shopId, $shopIds, true)) {
                return false;
            }
        }

        // Host filter
        $allowedHosts = (string) $this->config->getString(ConfigKeys::CFG_HOSTS, $shopId);
        if (trim($allowedHosts) !== '') {
            $hosts = array_values(array_filter(array_map('trim', explode(',', $allowedHosts))));
            $currentHost = (string) $this->server->getHost();
            if ($currentHost === '' || !in_array($currentHost, $hosts, true)) {
                return false;
            }
        }

        return true;
    }

    public function isCustomerAllowed(Customer $customer): bool
    {
        $shopId = $this->getShopId();

        $allowedGroupsRaw = (string) $this->config->getString(ConfigKeys::CFG_ALLOWED_GROUPS, $shopId);
        $allowedGroupsRaw = trim($allowedGroupsRaw);
        if ($allowedGroupsRaw === '') {
            return false;
        }

        $allowedGroupIds = array_values(array_filter(array_map(
            static fn(string $v): int => (int) trim($v),
            explode(',', $allowedGroupsRaw)
        ), static fn(int $v): bool => $v > 0));

        if ($allowedGroupIds === []) {
            return false;
        }

        foreach ($customer->getGroups() as $gid) {
            if (in_array((int) $gid, $allowedGroupIds, true)) {
                return true;
            }
        }

        return false;
    }

    public function assignPendingGroupIfNeeded(Customer $customer): void
    {
        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return;
        }

        $shopId = $this->getShopId();
        $pendingGroupId = (int) $this->config->getInt(ConfigKeys::CFG_PENDING_GROUP_ID, $shopId);
        if ($pendingGroupId <= 0) {
            return;
        }

        $groups = array_map('intval', $customer->getGroups());
        if (!in_array($pendingGroupId, $groups, true)) {
            $customer->addGroups([$pendingGroupId]);
        }
    }

    /* -------------------------------------------------------------------------
     * Unauthenticated handling
     * ---------------------------------------------------------------------- */

    private function handleUnauthenticatedLegacy(string $path): void
    {
        $shopId = $this->getShopId();

        // Endpoints techniques : réponse silencieuse (déjà filtré en amont, mais ceinture)
        if ($this->isTechnicalRequestLegacy($path)) {
            $this->sendNoContentAndExit();
        }

        // Bots => 403 si activé
        if ($this->isBot()) {
            $bots403 = (int) $this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403 === 1) {
                header('HTTP/1.1 403 Forbidden');
                exit('Access Denied');
            }
        }

        // Anti-boucle pending
        if ($this->isOnPendingPage($path)) {
            return;
        }

        // URL custom ?
        $humansRedirect = trim((string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId));
        if ($humansRedirect !== '') {
            $target = $this->sanitizeRedirectTarget($humansRedirect);
            $this->sendRedirectAndExit($target);
        }

        // Sinon => pending module
        $this->redirectToPendingLegacy();
    }

    private function createUnauthenticatedResponse(Request $request): Response
    {
        $shopId = $this->getShopId();
        $path = $this->normalizePath($request->getRequestUri());

        // endpoints techniques : pas de redirect
        if ($this->isTechnicalRequestSymfony($request, $path)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        // Bots => 403 si activé
        if ($this->isBot()) {
            $bots403 = (int) $this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403 === 1) {
                return new Response('Access Denied', Response::HTTP_FORBIDDEN);
            }
        }

        // Anti-boucle pending
        if ($this->isOnPendingPage($path)) {
            return new Response('', Response::HTTP_OK);
        }

        // URL custom ?
        $humansRedirect = trim((string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId));
        if ($humansRedirect !== '') {
            return new RedirectResponse($this->sanitizeRedirectTarget($humansRedirect), 302);
        }

        // Sinon => pending
        return $this->createPendingRedirect();
    }

    /* -------------------------------------------------------------------------
     * Paths & request classification
     * ---------------------------------------------------------------------- */

    private function normalizePath(string $uriOrPath): string
    {
        $path = (string) (parse_url($uriOrPath, PHP_URL_PATH) ?: $uriOrPath);
        $path = rtrim($path, '/');
        return $path === '' ? '/' : $path;
    }

    private function isModuleActionEndpoint(string $path): bool
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return false;
        }
        return (bool) preg_match('#^/module/[^/]+/(action|ajax|actions)(/|$)#i', $path);
    }

    private function isAllowedPath(string $path): bool
    {
        $path = $this->normalizePath($path);

        $shopId = $this->getShopId();
        $raw = trim((string) $this->config->getString(ConfigKeys::CFG_ALLOWED_PATHS, $shopId));
        if ($raw === '' || $path === '') {
            return false;
        }

        $prefixes = preg_split('/[\r\n,]+/', $raw) ?: [];
        foreach ($prefixes as $prefix) {
            $prefix = $this->normalizePath(trim((string) $prefix));

            // jamais "/" (ouvrirait tout), jamais vide
            if ($prefix === '/' || $prefix === '') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                return true;
            }
        }

        return false;
    }

    private function isTechnicalAssetAllowed(string $path): bool
    {
        $path = $this->normalizePath($path);

        foreach (['/themes/', '/assets/', '/img/', '/js/'] as $prefix) {
            if (str_starts_with($path . '/', $prefix)) {
                return true;
            }
        }

        return (bool) preg_match('#^/modules/[^/]+/views/#', $path);
    }

    /**
     * Undocumented function
     * isAjaxLegacy
     * @return boolean
     */
    private function isAjaxLegacy(): bool
    {
        $xrw = strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        if ($xrw === 'xmlhttprequest') {
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            return true;
        }

        return false;
    }

    /**
     * Undocumented function
     * isAjax
     * @return boolean
     */
    /*
        private function isAjax(): bool
        {
        
        }
    */
        
    private function isOnPendingPage(string $path): bool
    {
        $path = $this->normalizePath($path);
        return $path === '/module/ps_progate/pending' || str_starts_with($path, '/module/ps_progate/pending/');
    }

    private function isAdminPathAllowed(string $path): bool
    {
        $path = $this->normalizePath($path);
        if ($path === '/' || $path === '') {
            return false;
        }

        $adminBasename = '';

        if (\defined('_PS_ADMIN_DIR_')) {
            $adminDir = (string) \constant('_PS_ADMIN_DIR_');
            $adminBasename = trim(\basename(rtrim($adminDir, '/')), '/');
        }

        if ($adminBasename === '' && \defined('_PS_ADMIN_FOLDER_')) {
            $adminBasename = trim((string) \constant('_PS_ADMIN_FOLDER_'), '/');
        }

        if ($adminBasename === '' || strtolower($adminBasename) === 'admin') {
            return false;
        }

        $adminPrefix = '/' . $adminBasename . '/';
        $normalized = rtrim($path, '/') . '/';

        return \strpos($normalized, $adminPrefix) === 0;
    }

    /* -------------------------------------------------------------------------
     * Redirect helpers
     * ---------------------------------------------------------------------- */

    private function redirectToPendingLegacy(): void
    {
        $pendingUrl = $this->getPendingUrl();
        $this->sendRedirectAndExit($pendingUrl);
    }

    private function createPendingRedirect(): Response
    {
        return new RedirectResponse($this->getPendingUrl(), 302);
    }

    private function getPendingUrl(): string
    {
        $context = $this->getContext();
        return (string) $context->link->getModuleLink('ps_progate', 'pending');
    }

    private function sendRedirectAndExit(string $target): void
    {
        $target = $this->sanitizeRedirectTarget($target);

        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $target, true, 302);
        exit;
    }

    private function sendNoContentAndExit(): void
    {
        header('HTTP/1.1 204 No Content');
        exit;
    }

    private function sanitizeRedirectTarget(string $target): string
    {
        $target = trim(str_replace(["\r", "\n"], '', $target));
        if ($target === '') {
            return '/';
        }

        // Si c'est une URL absolue, on la garde telle quelle (si tu en as besoin)
        // mais on refuse les protocol-relative
        if (preg_match('#^(https?://)#i', $target)) {
            return $target;
        }
        if (preg_match('#^//#', $target)) {
            return '/';
        }

        // sinon on force en chemin relatif /xxx
        if ($target[0] !== '/') {
            $target = '/' . $target;
        }

        return $target;
    }

    /* -------------------------------------------------------------------------
     * Context & bots
     * ---------------------------------------------------------------------- */

    private function getContext(): Context
    {
        $ctx = $this->legacyContext->getContext();
        return $ctx instanceof Context ? $ctx : Context::getContext();
    }

    private function getShopId(): int
    {
        $context = $this->getContext();
        return (int) ($context->shop->id ?? 0);
    }

    private function isBot(): bool
    {
        $ua = strtolower((string) $this->server->getUserAgent());
        if ($ua === '') {
            return false;
        }

        $ip = (string) $this->server->getRemoteAddr();
        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '';
        }

        if ($ip === '') {
            if ($this->botVerifier->isClaimingGooglebot($ua) || $this->botVerifier->isClaimingBingbot($ua)) {
                return false;
            }
        }

        if ($this->botVerifier->isClaimingGooglebot($ua)) {
            return $this->botVerifier->isVerifiedGooglebot($ip);
        }

        if ($this->botVerifier->isClaimingBingbot($ua)) {
            return $this->botVerifier->isVerifiedBingbot($ip);
        }

        foreach ([
            'bot', 'crawl', 'spider', 'slurp',
            'duckduckbot', 'yandex', 'baidu',
            'ahrefs', 'semrush', 'dotbot', 'mj12bot', 'majestic',
            'facebot', 'twitterbot', 'linkedinbot',
            'whatsapp', 'telegram',
        ] as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return true;
            }
        }

        return false;
    }
}
