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
    ) {
        error_log(__METHOD__ . ' _____________________ HTTPS :: ' . $_SERVER['HTTPS']);
        error_log(__METHOD__ . ' _____________________ SERVER_PORT :: ' . $_SERVER['SERVER_PORT']);
        
        $customer = $this->getContext()->customer;
        error_log(__METHOD__ . ' _____________________ isLogged :: ' . ($customer && $customer->isLogged()? 'OUI' : 'non'));
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

        error_log(__METHOD__ . ' _____________________ path :: ' . $path);
        if ($path === '/connexion') {
            error_log(__METHOD__ . ' _____________________ METHOD :: ' . ($_SERVER['REQUEST_METHOD'] ?? ''));
            error_log(__METHOD__ . ' _____________________ POST keys :: ' . implode(',', array_keys($_POST ?? [])));
        }

        if ($this->isAdminPathAllowed($path) || $this->isEmployeeLogged()) {
            error_log(__METHOD__ . ' _____________________ path ADMIN out');
            return;
        }

        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return;
        }
        
        $isLoginPage = ($path === '/connexion' || $path === '/authentication' || $path === '/login');
        // Nettoyage back uniquement en GET (comme tu fais déjà)
        if ($isLoginPage && !empty($_GET['back'])) {
            error_log(__METHOD__ . ' _____________________ Back removed GET ' . $_GET['back']);
            unset($_GET['back']);
            $this->sendRedirectAndExit('/connexion');
        }

        if ($path === '/mon-compte' || $path === '/my-account') {
            error_log(__METHOD__ . ' _____________________ /mon-compte ou /my-account ');
            $customer = $this->getContext()->customer;
            if (!$customer || !$customer->isLogged()) {
                $this->sendRedirectAndExit('/connexion');
            }
            return;
        }

        if ($this->isLogoutRequest()) {
            // IMPORTANT: laisser PrestaShop faire CustomerCore::mylogout
            return;
        }

        if ($this->isAjaxLegacy() || $this->isModuleActionEndpoint($path)) {
            error_log(__METHOD__ . ' _____________________ isAjaxLegacy ou  isModuleActionEndpoint ' . $path);
            return;
        }
        
        $customer = $this->getContext()->customer;


        // IMPORTANT: laisser la home passer pour les clients connectés
        if ($path === '/' && $customer && $customer->isLogged()) {
            error_log(__METHOD__ . ' _____________________ laisser la home passer');
            return;
        }

        // 1) Pages toujours autorisées (anti-boucle / admin / whitelist / assets)
        if ($this->isAlwaysAllowedPath($path)) {
            return;
        }

        // 2)  connecté
        if ($customer && $customer->isLogged()) {
            // connecté mais pas autorisé => pending
            if (!$this->isCustomerAllowed($customer)) {
                $this->redirectToPendingLegacy();
            }
            return;
        }

        // Non connecté
        if (!$customer || !$customer->isLogged()) {
            $this->handleUnauthenticatedLegacy($path);
            return;
        }

    }

    public function enforceSymfony(Request $request): ?Response
    {
        // URL admin (au cas où) => ne jamais contrôler
        $path = $this->normalizePath($request->getRequestUri());
        error_log(__METHOD__ . ' _____________________ path :: ' . $path);

        if ($this->isAdminPathAllowed($path)  || $this->isEmployeeLogged()) {
            error_log(__METHOD__ . ' _____________________ path ADMIN out');
            return null;
        }

        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return null;
        }

        if ($path === '/connexion' && $request->query->has('back')) {
            error_log(__METHOD__ . ' _____________________ Block Back :: ' . $_GET['back']);
            return new RedirectResponse('/connexion', 302);
        }

        if ($request->query->has('mylogout')) {
            error_log(__METHOD__ . ' _____________________ Logout :: mylogout');
            // IMPORTANT: laisser PrestaShop faire CustomerCore::mylogout
            return null;
        }
        

        // 0) Endpoints techniques -> jamais de redirect HTML
        // MAIS: ne jamais couper pendant la page login (risque de casser la soumission et les scripts du thème)

        $isLoginPage = in_array($path, ['/connexion','/authentication','/login'], true);
        $isLoginCtx  = $isLoginPage || $this->isLoginContext();


        if (!$isLoginCtx && $this->isTechnicalRequestSymfony($request, $path)) {
            error_log(__METHOD__ . ' _____________________ isTechnicalRequestSymfony :: HTTP_NO_CONTENT');
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        $customer = $this->getContext()->customer;

        // IMPORTANT: laisser la home passer pour les clients connectés
        if ($path === '/' && $customer && $customer->isLogged()) {
            error_log(__METHOD__ . ' _____________________ laisser la home passer');
            return null;
        }

        // 1) Pages toujours autorisées (anti-boucle / admin / whitelist / assets)
        if ($this->isAlwaysAllowedPath($path)) {
            return null;
        }

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
        // On considère "contexte login" si la requête est /connexion
        // OU si elle provient d'une page login via Referer
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
        // pending doit être "hard-allowed"
        if ($this->isOnPendingPage($path)) {
            return true;
        }

        if ($this->isAdminPathAllowed($path)) {
            return true;
        }

        // ✅ NEW: autoriser dynamiquement la page HUMANS_REDIRECT
        $humansAllowed = $this->getHumansRedirectAllowedPath();
        if ($humansAllowed && ($path === $humansAllowed || str_starts_with($path, $humansAllowed . '/'))) {
            return true;
        }

        // ✅ NEW: autoriser dynamiquement la page HUMANS_REDIRECT
        $professionnalsAllowed = $this->getProfessionnalsRedirectAllowedPath();
        if ($professionnalsAllowed && ($path === $professionnalsAllowed || str_starts_with($path, $professionnalsAllowed . '/'))) {
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

    private function getProfessionnalsRedirectAllowedPath(): ?string
    {
        $shopId = $this->getShopId();
        $raw = trim((string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId));

        if ($raw === '') {
            return null;
        }

        // 1) ID CMS
        if (ctype_digit($raw) && (int) $raw > 0) {
            $url = $this->getContext()->link->getCMSLink((int) $raw);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $path = $this->normalizePath($path);
            return $path !== '/' ? $path : null;
        }

        // 2) URL absolue (on n'autorise que si même host)
        if (preg_match('#^https?://#i', $raw)) {
            $host = (string) (parse_url($raw, PHP_URL_HOST) ?: '');
            $host = preg_replace('#:\d+$#', '', $host) ?: $host;

            // sécurité : n'autoriser que si c'est bien le même host courant
            $currentHost = (string) $this->server->getHost();
            if ($host !== '' && $currentHost !== '' && strcasecmp($host, $currentHost) !== 0) {
                return null;
            }

            $path = (string) (parse_url($raw, PHP_URL_PATH) ?: '');
            $path = $this->normalizePath($path);
            return $path !== '/' ? $path : null;
        }

        // 3) chemin relatif /xxx ou xxx
        $path = $this->sanitizeRedirectTarget($raw);
        $path = $this->normalizePath($path);
        return $path !== '/' ? $path : null;
    }

    private function getHumansRedirectAllowedPath(): ?string
    {
        $shopId = $this->getShopId();
        $raw = trim((string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId));

        if ($raw === '') {
            return null;
        }

        // 1) ID CMS
        if (ctype_digit($raw) && (int) $raw > 0) {
            $url = $this->getContext()->link->getCMSLink((int) $raw);
            $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
            $path = $this->normalizePath($path);
            return $path !== '/' ? $path : null;
        }

        // 2) URL absolue (on n'autorise que si même host)
        if (preg_match('#^https?://#i', $raw)) {
            $host = (string) (parse_url($raw, PHP_URL_HOST) ?: '');
            $host = preg_replace('#:\d+$#', '', $host) ?: $host;

            // sécurité : n'autoriser que si c'est bien le même host courant
            $currentHost = (string) $this->server->getHost();
            if ($host !== '' && $currentHost !== '' && strcasecmp($host, $currentHost) !== 0) {
                return null;
            }

            $path = (string) (parse_url($raw, PHP_URL_PATH) ?: '');
            $path = $this->normalizePath($path);
            return $path !== '/' ? $path : null;
        }

        // 3) chemin relatif /xxx ou xxx
        $path = $this->sanitizeRedirectTarget($raw);
        $path = $this->normalizePath($path);
        return $path !== '/' ? $path : null;
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
        error_log(__METHOD__ . ' _____________________ allowedGroupsRaw :: ' . $allowedGroupsRaw);
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
            error_log(__METHOD__ . ' _____________________ foreach customer groups :: ' . $gid);
            if (in_array((int) $gid, $allowedGroupIds, true)) {
                error_log(__METHOD__ . ' _____________________ Client validé :: ' . $gid);
                return true;
            }
        }

        error_log(__METHOD__ . ' _____________________ Client rejeté');
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
                error_log(__METHOD__ . ' _____________________ Response::HTTP/1.1 403 Forbidden');
                header('HTTP/1.1 403 Forbidden');
                exit('Access Denied');
            }
        }

        // Anti-boucle pending
        if ($this->isOnPendingPage($path)) {
            return;
        }

        // URL custom ?
        $pendingCmsId = (string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if ($pendingCmsId !== '') {
            $humansRedirect = $this->resolveRedirectTarget($pendingCmsId);
            $this->sendRedirectAndExit($humansRedirect);
        }

        // Sinon => pending module
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

        // 1) ID CMS numérique
        if (ctype_digit($value)) {
            return $this->getCmsUrl((int) $value);
        }

        // 2) Déclaration module:controller
        // ex: module:ps_progate:pending
        if (str_starts_with($value, 'module:')) {
            [$type, $module, $controller] = explode(':', $value, 3);

            return $this->getContext()->link->getModuleLink(
                $module,
                $controller
            );
        }

        // 3) URL absolue (http/https)
        if (preg_match('#^https?://#i', $value)) {
            return $value;
        }

        // 4) Chemin relatif (/professionnels-8)
        return $this->sanitizeRedirectTarget($value);
    }

    private function createUnauthenticatedResponse(Request $request): Response
    {
        $shopId = $this->getShopId();
        $path = $this->normalizePath($request->getRequestUri());

        // endpoints techniques : pas de redirect
        if ($this->isTechnicalRequestSymfony($request, $path)) {
            error_log(__METHOD__ . ' _____________________ Response::HTTP_UNAUTHORIZED');
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        // Bots => 403 si activé
        if ($this->isBot()) {
            $bots403 = (int) $this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403 === 1) {
                error_log(__METHOD__ . ' _____________________ Response::HTTP_FORBIDDEN');
                return new Response('Access Denied', Response::HTTP_FORBIDDEN);
            }
        }

        // Anti-boucle pending
        if ($this->isOnPendingPage($path)) {
            error_log(__METHOD__ . ' _____________________ Response::HTTP_OK');
            return new Response('', Response::HTTP_OK);
        }

        // URL custom ?
        $pendingCmsId = (string) $this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if ($pendingCmsId !== '') {
            $humansRedirect = $this->resolveRedirectTarget($pendingCmsId);
            error_log(__METHOD__ . ' _____________________ RedirectResponse > ' . $humansRedirect);
            return new RedirectResponse($humansRedirect, 302);
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
            error_log(__METHOD__ . ' _____________________ Ajax (action|ajax|actions)');
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

            // jamais "/" (ouvrirait tout), jamais vide
            if ($prefix === '/' || $prefix === '') {
                continue;
            }

            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                error_log(__METHOD__ . ' _____________________ PASS :: ' . $path);
                return true;
            }
        }
        error_log(__METHOD__ . ' _____________________ BLOCKED :: ' . $path);
        return false;
    }

    private function isTechnicalAssetAllowed(string $path): bool
    {
        error_log(__METHOD__ . ' _____________________ path :: ' . $path);
        $path = $this->normalizePath($path);

        foreach (['/themes/', '/assets/', '/img/', '/js/'] as $prefix) {
            if (str_starts_with($path . '/', $prefix)) {
                error_log(__METHOD__ . ' _____________________ is ' . $prefix);
                return true;
            }
        }

        if((bool) preg_match('#^/modules/[^/]+/views/#', $path))
        {
            error_log(__METHOD__ . ' _____________________ is modules views');
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
            error_log(__METHOD__ . ' _____________________ Ajax xmlhttprequest');
            return true;
        }

        $accept = strtolower((string) ($_SERVER['HTTP_ACCEPT'] ?? ''));
        if ($accept !== '' && str_contains($accept, 'application/json')) {
            error_log(__METHOD__ . ' _____________________ Ajax HTTP_ACCEPT');
            return true;
        }

        return false;
    }
        
    private function isOnPendingPage(string $path): bool
    {
        $path = $this->normalizePath($path);
        if($path === '/module/ps_progate/pending' || str_starts_with($path, '/module/ps_progate/pending/'))
        {
            error_log(__METHOD__ . ' _____________________ Is Pending route');
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

        error_log(__METHOD__ . " _____________________ strpos($normalized, $adminPrefix)");
        return \strpos($normalized, $adminPrefix) === 0;
    }

    private function isLogoutRequest(): bool
    {
        // 1) Query-string brute
        $qs = (string) ($_SERVER['QUERY_STRING'] ?? '');
        if ($qs !== '') {
            // match mylogout en tant que param: mylogout ou mylogout=...
            if (preg_match('/(^|&)(mylogout)(=|&|$)/i', $qs)) {
                error_log(__METHOD__ . ' _____________________ Logout [QUERY_STRING] :: ' . $qs);
                return true;
            }
        }

        // 2) Fallback: URI brute (au cas où)
        $uri = (string) $this->server->getRequestUri();
        if ($uri !== '' && preg_match('/[?&](mylogout)(=|&|$)/i', $uri)) {
            error_log(__METHOD__ . ' _____________________ Logout getRequestUri() :: ' . $uri);
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
        error_log(__METHOD__ . ' _____________________ RedirectResponse > ' . $this->getPendingUrl());
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
        error_log(__METHOD__ . ' _____________________ Location  --> ' . $target);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $target, true, 302);
        exit;
    }

    private function sendNoContentAndExit(): void
    {
        error_log(__METHOD__ . ' _____________________  header(HTTP/1.1 204 No Content)');
        header('HTTP/1.1 204 No Content');
        exit;
    }

    private function sanitizeRedirectTarget(string $target): string
    {
        error_log(__METHOD__ . ' _____________________ target :: ' . $target);
        $target = trim(str_replace(["\r", "\n"], '', $target));
        if ($target === '') {
            error_log(__METHOD__ . ' _____________________ return :: / ');
            return '/';
        }

        // Si c'est une URL absolue, on la garde telle quelle (si tu en as besoin)
        // mais on refuse les protocol-relative
        if (preg_match('#^(https?://)#i', $target)) {
            error_log(__METHOD__ . ' _____________________ return :: ' . $target);
            return $target;
        }
        if (preg_match('#^//#', $target)) {
            error_log(__METHOD__ . ' _____________________ return :: / ');
            return '/';
        }

        // sinon on force en chemin relatif /xxx
        if ($target[0] !== '/') {
            $target = '/' . $target;
        }

        error_log(__METHOD__ . ' _____________________ return :: ' . $target);
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
