<?php
declare(strict_types=1);

namespace Ps_ProGate\Service;

use Context;
use Customer;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Ps_ProGate\Config\ConfigKeys;
use Ps_ProGate\Infra\ConfigReaderInterface;
use Ps_ProGate\Infra\ServerBagInterface;
use Ps_ProGate\Infra\RedirectorInterface;
use Ps_ProGate\Infra\CookieJarInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final class AccessGate implements AccessGateInterface
{
    private const POST_LOGOUT_COOKIE = 'ps_progate_post_logout';
    
    public function __construct(
        private CookieJarInterface $cookies,
        private UrlGeneratorInterface $router,
        private LegacyContext $legacyContext,
        private ConfigReaderInterface $config,
        private ServerBagInterface $server,
        private SearchBotVerifier $botVerifier,
        private RedirectorInterface $redirector,
    ) {
    }

    /* -------------------------------------------------------------------------
     * Public API
     * ---------------------------------------------------------------------- */

    public function enforceLegacy(): void
    {
        $path = $this->normalizePath($this->server->getRequestUri());

        // 0) Endpoints techniques modules : ne jamais contrôler, ne jamais rediriger
        if ($this->isModuleActionEndpoint($path)) {
            return;
        }

        if ($this->isAdminPathAllowed($path) || $this->isEmployeeLogged()) {
            return;
        }

        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return;
        }
        
        $isLoginPage = ($path === '/connexion' || $path === '/authentication' || $path === '/login');
        if ($isLoginPage && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && isset($_GET['back']) && $_GET['back'] !== '') {
            $this->sendRedirectAndExit('/connexion');
        }

        if ($path === '/mon-compte' || $path === '/my-account') {
            $customer = $this->getContext()->customer;
            if (!$customer || !$customer->isLogged()) {
                $this->sendRedirectAndExit('/connexion');
            }
            return;
        }

        if ($this->isLogoutRequest()) {
            $this->markPostLogout();
            return;
        }

        if ($path === '/connexion' && $this->cookies->get('ps_progate_post_logout') === '1') {
            $this->cookies->delete('ps_progate_post_logout');

            $target = $this->getHumansRedirectUrl();
            if ($target !== null) {
                $this->redirector->redirectAndExit($target);
            }

            // fallback sécurité
            $this->redirector->redirectAndExit('/');
        }

        if ($this->isAjaxLegacy() || $this->isModuleActionEndpoint($path)) {
            return;
        }
        
        $customer = $this->getContext()->customer;

        if ($path === '/' && $customer && $customer->isLogged()) {
            return;
        }

        if ($this->isAlwaysAllowedPath($path)) {
            return;
        }

        if ($customer && $customer->isLogged()) {
            if (!$this->isCustomerAllowed($customer)) {
                $this->redirectToPendingLegacy();
            }
            return;
        }

        if (!$customer || !$customer->isLogged()) {
            $this->handleUnauthenticatedLegacy($path);
            return;
        }

    }

    private function getHumansRedirectUrl(): ?string
    {
        $shopId = $this->getShopId();
        $raw = trim((string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId));

        if ($raw === '') {
            return null;
        }

        return $this->resolveRedirectTarget($raw);
    }

    public function enforceSymfony(Request $request): ?Response
    {
        $path = $this->normalizePath($request->getRequestUri());

        if ($this->isAdminPathAllowed($path)  || $this->isEmployeeLogged()) {
            return null;
        }

        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return null;
        }

        if ($path === '/connexion' && $request->query->has('back')) {
            return new RedirectResponse('/connexion', 302);
        }

        if ($request->query->has('mylogout')) {
            return null;
        }
        

        $isLoginPage = in_array($path, ['/connexion','/authentication','/login'], true);
        $isLoginCtx  = $isLoginPage || $this->isLoginContext();


        if (!$isLoginCtx && $this->isTechnicalRequestSymfony($request, $path)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $customer = $this->getContext()->customer;

        if ($path === '/' && $customer && $customer->isLogged()) {
            return null;
        }

        if ($this->isAlwaysAllowedPath($path)) {
            return null;
        }

        if (!$customer || !$customer->isLogged()) {
            return $this->createUnauthenticatedResponse($request);
        }

        if (!$this->isCustomerAllowed($customer)) {
            return $this->createPendingRedirect();
        }

        return null;
    }

    /* -------------------------------------------------------------------------
     * Core decisions
     * ---------------------------------------------------------------------- */

    private function isLoginContext(): bool
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') {
            return false;
        }
        $p = (string) (parse_url($ref, PHP_URL_PATH) ?: '');
        $p = $this->normalizePath($p);

        return in_array($p, ['/connexion', '/authentication', '/login'], true);
    }

    private function isLoginContextLegacy(): bool
    {
        $ref = (string) ($_SERVER['HTTP_REFERER'] ?? '');
        if ($ref === '') {
            return false;
        }

        $refPath = (string) (parse_url($ref, PHP_URL_PATH) ?: '');
        $refPath = $this->normalizePath($refPath);

        return in_array($refPath, ['/connexion', '/authentication', '/login'], true);
    }

    private function isAlwaysAllowedPath(string $path): bool
    {
        if ($this->isOnPendingPage($path)) {
            return true;
        }

        if ($this->isAdminPathAllowed($path)) {
            return true;
        }

        $redirectAllowed = $this->getRedirectAllowedPath(ConfigKeys::CFG_HUMANS_REDIRECT);
        if ($redirectAllowed && ($path === $redirectAllowed || str_starts_with($path, $redirectAllowed . '/'))) {
            return true;
        }

        $redirectAllowed = $this->getRedirectAllowedPath(ConfigKeys::CFG_PROFESSIONALS_REDIRECT);
        if ($redirectAllowed && ($path === $redirectAllowed || str_starts_with($path, $redirectAllowed . '/'))) {
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

    private function getRedirectAllowedPath(string $configKey): ?string
    {
        $shopId = $this->getShopId();
        $raw = trim((string) $this->config->getString($configKey, $shopId));

        if ($raw === '') {
            return null;
        }

        // Résout en URL/chemin
        $target = $this->resolveRedirectTarget($raw);

        // On whitelist seulement si c’est le même host (si URL absolue)
        if (preg_match('#^https?://#i', $target)) {
            $host = (string) (parse_url($target, PHP_URL_HOST) ?: '');
            $host = preg_replace('#:\d+$#', '', $host) ?: $host;

            $currentHost = (string) $this->server->getHost();
            if ($host !== '' && $currentHost !== '' && strcasecmp($host, $currentHost) !== 0) {
                return null;
            }

            $path = (string) (parse_url($target, PHP_URL_PATH) ?: '');
            $path = $this->normalizePath($path);
            return ($path !== '/') ? $path : null;
        }

        // Target relatif
        $path = $this->normalizePath($target);

        // Jamais "/" (sinon tu ouvres tout)
        return ($path !== '/') ? $path : null;
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

        if ($this->isAjaxLegacy() || $this->isModuleActionEndpoint($path)) {
            return;
        }

        // Bots => 403 si activé
        if ($this->isBot()) {
            $bots403 = (int) $this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403 === 1) {
                header('HTTP/1.1 403 Forbidden');
                exit('Access Denied');
            }
        }

        if ($this->isOnPendingPage($path)) {
            return;
        }

        $pendingCmsId = (string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if ($pendingCmsId !== '') {
            $humansRedirect = $this->resolveRedirectTarget($pendingCmsId);
            $this->sendRedirectAndExit($humansRedirect);
        }

        $this->redirectToPendingLegacy();
    }

    private function getCmsUrl(int $cmsId): string
    {
        $context = $this->getContext();

        return $context->link->getCMSLink(
            $cmsId,
            null,
            null,
            (int) $context->language->id,
            (int) $context->shop->id
        );
    }

    private function resolveRedirectTarget(string $value): string
    {
        $value = trim($value);

        if ($value === '') {
            return '/';
        }

        if (ctype_digit($value)) {
            return $this->getCmsUrl((int) $value);
        }

        if (str_starts_with($value, 'module:')) {
            [$type, $module, $controller] = explode(':', $value, 3);

            return $this->getContext()->link->getModuleLink(
                $module,
                $controller
            );
        }

        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        return $this->sanitizeRedirectTarget($value);
    }

    private function createUnauthenticatedResponse(Request $request): Response
    {
        $shopId = $this->getShopId();
        $path = $this->normalizePath($request->getRequestUri());

        if ($this->isTechnicalRequestSymfony($request, $path)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        if ($this->isBot()) {
            $bots403 = (int) $this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403 === 1) {
                return new Response('Access Denied', Response::HTTP_FORBIDDEN);
            }
        }

        if ($this->isOnPendingPage($path)) {
            return new Response('', Response::HTTP_OK);
        }

        $pendingCmsId = (string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if ($pendingCmsId !== '') {
            $humansRedirect = $this->resolveRedirectTarget($pendingCmsId);
            return new RedirectResponse($humansRedirect, 302);
        }

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

    private function isEmployeeLogged(): bool
    {
        $ctx = $this->getContext();
        return isset($ctx->employee) && $ctx->employee && (int) $ctx->employee->id > 0;
    }

    private function isModuleActionEndpoint(string $path): bool
    {
        $path = rtrim($path, '/');
        if ($path === '') {
            return false;
        }
        if((bool) preg_match('#^/module/[^/]+/(action|ajax|actions)(/|$)#i', $path))
        {
            return true;
        }
        return false;
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

            if ($prefix === '/' || $prefix === '') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                // error_log(__METHOD__ . ' _____________________ PASS :: ' . $path);
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

        if((bool) preg_match('#^/modules/[^/]+/views/#', $path))
        {
            return true;
        } 
        
        return false;
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
        
    private function isOnPendingPage(string $path): bool
    {
        $path = $this->normalizePath($path);
        if($path === '/module/ps_progate/pending' || str_starts_with($path, '/module/ps_progate/pending/'))
        {
            return true;
        } else
            return false; 
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

    private function isLogoutRequest(): bool
    {
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            if (preg_match('/(^|&)(mylogout)(=|&|$)/i', $qs)) {
                return true;
            }
        }

        $uri = (string) $this->server->getRequestUri();
        if ($uri !== '' && preg_match('/[?&](mylogout)(=|&|$)/i', $uri)) {
            return true;
        }

        return false;
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
        $this->redirector->redirectAndExit($target);
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

        if (preg_match('#^(https?://)#i', $target)) {
            return $target;
        }
        if (preg_match('#^//#', $target)) {
            return '/';
        }

        if ($target[0] !== '/') {
            $target = '/' . $target;
        }

        return $target;
    }

    private function markPostLogout(): void
    {
        $this->cookies->set(self::POST_LOGOUT_COOKIE, '1', 60);
    }

    private function consumePostLogout(): bool
    {
        if ($this->cookies->get(self::POST_LOGOUT_COOKIE) === null) {
            return false;
        }
        $this->cookies->clear(self::POST_LOGOUT_COOKIE);
        return true;
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
