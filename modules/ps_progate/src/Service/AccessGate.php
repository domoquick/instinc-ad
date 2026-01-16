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

    public function __construct(
        UrlGeneratorInterface $router,
        LegacyContext $legacyContext,
        ConfigReaderInterface $config,
        ServerBagInterface $server,
        SearchBotVerifier $botVerifier
    ) {

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
        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return;
        }

        $currentPath = $this->server->getRequestUri();
        $pathOnly = (string)(parse_url($currentPath, PHP_URL_PATH) ?: $currentPath);

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
        if (!$this->isGateActiveForCurrentShopAndHost()) {
            return null;
        }

        $uri = $request->getRequestUri();
        $path = parse_url($uri, PHP_URL_PATH) ?? '/';

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
        $shopId = $this->getShopId();

        $allowedPathsRaw = (string)$this->config->getString(ConfigKeys::CFG_ALLOWED_PATHS, $shopId);
        if (trim($allowedPathsRaw) === '') {
            return false;
        }

        $paths = preg_split('/[\n,]+/', $allowedPathsRaw) ?: [];
        foreach ($paths as $prefix) {
            $prefix = trim((string)$prefix);
            // IMPORTANT: never allow "/" as prefix (it would open everything)
            if ($prefix === '/' || $prefix === '') {
                continue;
            }

            if (strpos($path, $prefix) === 0) {
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

        if (preg_match('#^/modules/[^/]+/views/#', $path)) return true;

        return false;
    }

    private function handleUnauthenticatedLegacy(string $backPath): void
    {
        $shopId = $this->getShopId();

        if ($this->isBot()) {
            $bots403 = (int)$this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403) {
                header('HTTP/1.1 403 Forbidden');
                exit('Access Denied proGate-' . __LINE__);
            }
        }
  
        if ($this->isOnAuthenticationPage($backPath)) {
            return;
        }

        $context = $this->getContext();
        $back = $this->normalizeBackForAuth($backPath);
        $humansRedirect = (string)$this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if (trim($humansRedirect) !== '') {
            Tools::redirect($humansRedirect);
        }

        $loginUrl = $context->link->getPageLink('authentication', true, null, null);

        Tools::redirect($loginUrl);

    }

    private function createUnauthenticatedResponse(Request $request): Response
    {
        $shopId = $this->getShopId();

        if ($this->isBot()) {
            $bots403 = (int)$this->config->getInt(ConfigKeys::CFG_BOTS_403, $shopId);
            if ($bots403) {
                return new Response('Access Denied proGate-' . __LINE__ , Response::HTTP_FORBIDDEN);
            }
        }

        $path = rtrim($request->getPathInfo(), '/');

        // 🔐 ANTI-BOUCLE
        if ($this->isOnAuthenticationPage($path)) {
            return new Response('', Response::HTTP_OK);
        }

        $humansRedirect = (string)$this->config->getString(ConfigKeys::CFG_HUMANS_REDIRECT, $shopId);
        if (trim($humansRedirect) !== '') {
            return new RedirectResponse($humansRedirect);
        }

        $context = $this->getContext();
        $back = $this->normalizeBackForAuth($request->getPathInfo());
        $loginUrl = $context->link->getPageLink('authentication', true, null, ['back' => $back]);

        return new RedirectResponse($loginUrl);

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

    private function isOnAuthenticationPage(string $path): bool
    {
        $path = rtrim($path, '/');

        // URLs SEO possibles
        return in_array($path, [
            '/authentication',
            '/connexion',
            '/login',
        ], true);
    }

    private function redirectToPendingLegacy(): void
    {
        $context = $this->getContext();
        $pendingUrl = $context->link->getModuleLink('ps_progate', 'pending');
        Tools::redirect($pendingUrl);
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
        $path = trim($path);
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
