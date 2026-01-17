<?php
declare(strict_types=1);

namespace Ps_ProGate\Service;

use Context;
use Customer;
use Tools;
use PrestaShop\PrestaShop\Adapter\LegacyContext;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Ps_ProGate\Infra\ConfigReaderInterface;
use Ps_ProGate\Infra\ServerBagInterface;

use Ps_ProGate\Config\ConfigKeys;
use Ps_ProGate\Service\SearchBotVerifier;

final class AccessGate implements AccessGateInterface
{
    private UrlGeneratorInterface $router;
    private LegacyContext $legacyContext;
    private ConfigReaderInterface $config;
    private ServerBagInterface $server;
    private SearchBotVerifier $botVerifier;
    private int $tache;

    public function __construct(
        UrlGeneratorInterface $router,
        LegacyContext $legacyContext,
        ConfigReaderInterface $config,
        ServerBagInterface $server,
        SearchBotVerifier $botVerifier
    ) {
        $this->tache = 0;
        $this->router = $router;
        $this->legacyContext = $legacyContext;
        $this->config = $config;
        $this->server = $server;
        $this->botVerifier = $botVerifier;
    }

    private function getContext(): Context
    {
        $ctx = $this->legacyContext->getContext();
        if ($ctx instanceof Context) {
            return $ctx;
        }
        return Context::getContext();
    }

    private function getShopId(): int
    {
        $context = $this->getContext();
        return (int)($context->shop->id ?? 0);
    }

    public function enforceLegacy(): void
    {
        $this->tache = random_int(1,9);

        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return;
        }

        $currentPath = $this->server->getRequestUri();
        $pathOnly = (string)(parse_url($currentPath, PHP_URL_PATH) ?: $currentPath);
        $pathOnly = rtrim($pathOnly, '/');

        if ($this->isAjax() || $this->isModuleActionEndpoint($pathOnly)) {
            header('HTTP/1.1 204 No Content');
            exit;
        }

        if ($pathOnly === '/module/ps_progate/pending') {
            return;
        }

        if ($this->isOnPendingPage($pathOnly /* ou $path */)) {
            return;
        }

        if ($this->isAdminPathAllowed($pathOnly)) {
            return;
        }

        if ($this->isAllowedPath($pathOnly)) {
            return;
        }

        if ($this->isTechnicalAssetAllowed($pathOnly)) {
            return;
        }

        $context = $this->getContext();
        $customer = $context->customer;

        if (!$customer || !$customer->isLogged()) {
            $this->handleUnauthenticatedLegacy($pathOnly);
            return;
        }

        if (!$this->isCustomerAllowed($customer)) {
            $this->redirectToPendingLegacy();
            return;
        }

    }

    public function enforceSymfony(Request $request): ?Response
    {
        $this->tache = random_int(1,9);
        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return null;
        }

        $uri = $request->getRequestUri();
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';
        $path = rtrim($path, '/');

        if ($this->isAjax() || $this->isModuleActionEndpoint($path)) {
            return new Response('', Response::HTTP_NO_CONTENT);
        }

        if ($path === '/module/ps_progate/pending') {
            return null;
        }

        if ($this->isOnPendingPage($path)) {
            return null;
        }

        if ($this->isAdminPathAllowed($path)) {
            return null;
        }
        
        if ($this->isAllowedPath($path) || $this->isTechnicalAssetAllowed($path)) {
            return null;
        }
        $context = $this->getContext();
        $customer = $context->customer;

        if (!$customer || !$customer->isLogged()) {
            return $this->createUnauthenticatedResponse($request);
        }

        if (!$this->isCustomerAllowed($customer)) {
            return $this->createPendingRedirect();
        }

        return null;
    }

    private function isModuleActionEndpoint(string $path): bool
    {
        $path = rtrim($path, '/');

        // cas typiques : /module/<name>/action, /module/<name>/ajax, /module/<name>/actions
        return (bool) preg_match('#^/module/[^/]+/(action|ajax|actions)(/|$)#i', $path);
    }

    public function isGateActiveForCurrentShopAndHost(): bool
    {
        $shopId = $this->getShopId();

        $enabled = (int)$this->config->getInt(ConfigKeys::CFG_ENABLED, $shopId);
        if (!$enabled) {
            return false;
        }

        $targetShopIds = (string)$this->config->getString(ConfigKeys::CFG_SHOP_IDS, $shopId);
        if (trim($targetShopIds) !== '') {
            $shopIds = array_map('intval', array_map('trim', explode(',', $targetShopIds)));
            if (!in_array($shopId, $shopIds, true)) {
                return false;
            }
        }

        $allowedHosts = (string)$this->config->getString(ConfigKeys::CFG_HOSTS, $shopId);
        if (trim($allowedHosts) !== '') {
            $hosts = array_map('trim', explode(',', $allowedHosts));
            $currentHost = (string)$this->server->getHost();
            if (!in_array($currentHost, $hosts, true)) {
                return false;
            }
        }

        return true;
    }

    public function isCustomerAllowed(Customer $customer): bool
    {
        $shopId = $this->getShopId();

        $allowedGroups = (string)$this->config->getString(ConfigKeys::CFG_ALLOWED_GROUPS, $shopId);
        if (trim($allowedGroups) === '') {
            return false;
        }

        $groupIds = array_map('intval', array_map('trim', explode(',', $allowedGroups)));
        $customerGroups = $customer->getGroups();

        foreach ($customerGroups as $gid) {
            if (in_array((int)$gid, $groupIds, true)) {
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
        $pendingGroupId = (int)$this->config->getInt(ConfigKeys::CFG_PENDING_GROUP_ID, $shopId);

        if ($pendingGroupId <= 0) {
            return;
        }

        $groups = $customer->getGroups();
        if (!in_array($pendingGroupId, array_map('intval', $groups), true)) {
            $customer->addGroups([$pendingGroupId]);
        }
    }

    private function isAllowedPath(string $path): bool
    {
        $path = rtrim(trim((string)$path), '/');
        $shopId = $this->getShopId();

        $allowedPathsRaw = (string)$this->config->getString(ConfigKeys::CFG_ALLOWED_PATHS, $shopId);
        
        if (trim($allowedPathsRaw) === '' || trim($path) === '' ) {
            return false;
        }
        
        $paths = preg_split('/[\n,]+/', $allowedPathsRaw) ?: [];

        foreach ($paths as $prefix) {
            $prefix = rtrim(trim((string)$prefix), '/');
            // IMPORTANT: never allow "/" as prefix (it would open everything)
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
        if (strpos($path, '/themes/') === 0) return true;
        if (strpos($path, '/assets/') === 0) return true;
        if (strpos($path, '/img/') === 0) return true;
        if (strpos($path, '/js/') === 0) return true;

        if (preg_match('#^/modules/[^/]+/views/#', $path)) {
            return true;
            }

        return false;
    }

    private function isAjax(): bool
    {
        $xrw = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
        $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

        return $xrw === 'xmlhttprequest' || str_contains($accept, 'application/json');
        
        $xrw = (string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '');
        if (strtolower($xrw) === 'xmlhttprequest') {
            return true;
        }

        $accept = (string)($_SERVER['HTTP_ACCEPT'] ?? '');
        if (stripos($accept, 'application/json') !== false) {
            return true;
        }

        // Optionnel: certains fetch envoient ce header
        $fetch = (string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '');
        if (strtolower($fetch) === 'cors') {
            return true;
        }

        return false;
    }

    private function handleUnauthenticatedLegacy(string $backPath): void
    {
        $shopId = $this->getShopId();

        if ($this->isAjax() || $this->isModuleActionEndpoint($backPath)) {
            header('HTTP/1.1 204 No Content');
            exit;
        }

        // 1) Bots => 403 si activé
        if ($this->isBot()) {
            $bots403 = (int)$this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403) {
                header('HTTP/1.1 403 Forbidden');
                exit('Access Denied');
            }
        }

        // 2) Anti-boucle : si on est déjà sur pending, ne rien faire
        if ($this->isOnPendingPage($backPath)) {
            return;
        }

        // 3) Si une URL custom est définie, on redirige dessus
        $humansRedirect = (string)$this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if (trim($humansRedirect) !== '') {
            header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
            header('Pragma: no-cache');
            header('Location: ' . $humansRedirect, true, 302);
            exit;
        }

        // 4) Sinon => pending
        $this->redirectToPendingLegacy();
    }

    private function createUnauthenticatedResponse(Request $request): Response
    {
        $shopId = $this->getShopId();
        $path = $request->getPathInfo();

        if ($request->isXmlHttpRequest() || $this->isModuleActionEndpoint($path)) {
            return new Response('', Response::HTTP_UNAUTHORIZED);
        }

        // 1) Bots => 403 si activé
        if ($this->isBot()) {
            $bots403 = (int)$this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403) {
                return new Response('Access Denied', Response::HTTP_FORBIDDEN);
            }
        }


        // 2) Anti-boucle : si déjà sur pending, ne rien faire
        if ($this->isOnPendingPage($path)) {
            return new Response('', Response::HTTP_OK);
        }

        // 3) URL custom ?
        $humansRedirect = (string)$this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if (trim($humansRedirect) !== '') {
            return new RedirectResponse($humansRedirect);
        }

        // 4) Sinon => pending
        return $this->createPendingRedirect();
    }

    private function normalizeBackForAuth(string $back): string
    {
        $back = $this->sanitizeBack($back);

        // évite back=/connexion ou back=/authentication
        if ($back === '/connexion' || $back === '/authentication') {
            return '/';
        }

        // optionnel : couvre aussi les variantes avec slash final
        if ($back === '/connexion/' || $back === '/authentication/') {
            return '/';
        }

        return $back;
    }

    private function isOnPendingPage(string $path): bool
    {
        $path = rtrim($path, '/');
        return str_starts_with($path, '/module/ps_progate/pending');
    }

    private function redirectToPendingLegacy(): void
    {
        $context = $this->getContext();
        $pendingUrl = $context->link->getModuleLink('ps_progate', 'pending');
        //INFO :: ça ne foonctionne pas au 01/2026
        // Tools::redirect($pendingUrl);
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Location: ' . $pendingUrl, true, 302);
        exit;
    }

    private function createPendingRedirect(): Response
    {
        $context = $this->getContext();
        $pendingUrl = $context->link->getModuleLink('ps_progate', 'pending');
        return new RedirectResponse($pendingUrl);
    }

    private function sanitizeBack(string $back): string
    {
        $back = trim($back);
        if ($back === '') {
            return '/';
        }

        // refuse absolute URLs and protocol-relative URLs
        if (preg_match('#^(https?:)?//#i', $back)) {
            return '/';
        }

        // remove CRLF to prevent header injection
        $back = str_replace(["\r", "\n"], '', $back);

        // ensure it starts with /
        if ($back[0] !== '/') {
            $back = '/' . $back;
        }

        // keep only path, drop query + fragment (match unit test expectation)
        $parts = parse_url($back);
        if ($parts === false) {
            return '/';
        }

        $path = $parts['path'] ?? '/';
        if ($path === '') {
            $path = '/';
        }

        return $path;
    }

    private function isBot(): bool
    {
        $ua = strtolower((string)$this->server->getUserAgent());
        if ($ua === '') {
            return false;
        }

        $ip = (string) $this->server->getRemoteAddr();

        if ($ip !== '' && !filter_var($ip, FILTER_VALIDATE_IP)) {
            $ip = '';
        }

        // 0. Cas IP vide
        if ($ip === '') {
            // Si l'UA prétend être Google/Bing mais pas d'IP fiable => considérer comme spoof
            if ($this->botVerifier->isClaimingGooglebot($ua) || $this->botVerifier->isClaimingBingbot($ua)) {
                return false;
            }
        }
        
        // 1. Cas Googlebot
        if ($this->botVerifier->isClaimingGooglebot($ua)) {
            return $this->botVerifier->isVerifiedGooglebot($ip);
        }

        // 2. Cas Bingbot
        if ($this->botVerifier->isClaimingBingbot($ua)) {
            return $this->botVerifier->isVerifiedBingbot($ip);
        }

        // 3. Autres bots "classiques" (non critiques)
        $genericPatterns = [
            'bot', 'crawl', 'spider', 'slurp',
            'duckduckbot', 'yandex', 'baidu',
            'ahrefs', 'semrush', 'dotbot', 'mj12bot', 'majestic',
            'facebot', 'twitterbot', 'linkedinbot',
            'whatsapp', 'telegram',
        ];

        foreach ($genericPatterns as $pattern) {
            if (strpos($ua, $pattern) !== false) {
                return true;
            }
        }
        return false;
    }

    private function isAdminPathAllowed(string $path): bool
    {
        $path = rtrim($path);
        if ($path === '') {
            return false;
        }

        // 1) Récupère le nom du dossier admin depuis les constantes disponibles
        $adminBasename = '';

        // Meilleur cas: chemin complet
        if (\defined('_PS_ADMIN_DIR_')) {
            $adminDir = (string) \constant('_PS_ADMIN_DIR_');
            $adminBasename = trim(\basename(rtrim($adminDir, '/')), '/');
        }

        // Fallback fréquent: nom du dossier admin uniquement
        if ($adminBasename === '' && \defined('_PS_ADMIN_FOLDER_')) {
            $adminBasename = trim((string) \constant('_PS_ADMIN_FOLDER_'), '/');
        }

        // Si on ne peut pas déterminer l’admin => on ne “devine” pas
        if ($adminBasename === '' || strtolower($adminBasename) === 'admin') {
            return false;
        }

        // 2) Match strict du préfixe /<adminBasename>/
        $adminPrefix = '/' . $adminBasename . '/';
        $normalized = rtrim($path, '/') . '/';

        return \strpos($normalized, $adminPrefix) === 0;
    }

}
