<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/HubKeyVerifier.php';

/**
 * Callback PUBLIC (aucun JWT) appelé par le hub push centralisé (push.rebuild-it.fr) pour livrer
 * une nouvelle clé de licence suite à un `POST /v1/licenses/recover` — récupération self-service
 * d'une licence perdue (ex. réinstallation du module), basée sur la preuve de contrôle du domaine
 * (cf. rebuild-it/docs/push-recover.md, hors périmètre de ce repo).
 *
 * Appelé par le hub via l'URL legacy (format fixé par le contrat hub) :
 *   POST https://{shop_url}/index.php?fc=module&module=rebuildconnector&controller=hubkey
 *   Corps : { "payload": "<chaîne JSON brute>", "signature": "<base64>" }
 *
 * Sécurité : PAS d'authentification Bearer (le hub n'a justement plus de clé valide pour cette
 * boutique). L'authenticité repose entièrement sur la signature RSA du hub, vérifiée via
 * {@see HubKeyVerifier}. `payload` est une chaîne JSON DÉJÀ sérialisée côté hub : la signature est
 * vérifiée sur cette chaîne brute, jamais sur une ré-sérialisation de son contenu décodé.
 *
 * Ne stocke la nouvelle clé qu'après : (1) signature valide, (2) shop_url correspondant au domaine
 * réel de cette boutique (preuve de contrôle du domaine), (3) issued_at dans la fenêtre anti-rejeu.
 * Toute erreur → 400/401, rien n'est stocké, la clé n'est jamais logguée en clair.
 */
class RebuildconnectorHubkeyModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?HubKeyVerifier $verifier = null;

    /**
     * L'allowlist IP restreint l'API métier (Bearer JWT) à des IP connues de la boutique (ex. VPN).
     * Elle n'a pas de sens ici : ce callback est appelé depuis l'infrastructure du hub (Cloudflare
     * Workers), dont les IP sortantes ne sont ni stables ni communiquées à l'avance. L'appliquer
     * casserait la récupération de licence précisément pour les boutiques qui activent l'allowlist.
     * L'authenticité de la requête est garantie par la signature RSA, pas par son IP source.
     */
    protected function isIpAllowlistEnforced(): bool
    {
        return false;
    }

    public function initContent(): void
    {
        parent::initContent();

        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
            // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
            // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
            // en production (le header Allow est informatif sur une 405).
            @header('Allow: POST');
            $this->renderJson([
                'error' => 'method_not_allowed',
                'message' => $this->t('api.error.method_not_allowed', [], 'This endpoint only accepts POST requests.'),
            ], 405);

            return;
        }

        try {
            $envelope = $this->decodeRequestBody();
        } catch (\InvalidArgumentException $exception) {
            $this->renderJson([
                'error' => 'invalid_request',
                'message' => $exception->getMessage(),
            ], 400);

            return;
        }

        if (!isset($envelope['payload'], $envelope['signature'])
            || !is_string($envelope['payload'])
            || !is_string($envelope['signature'])
        ) {
            $this->renderJson([
                'error' => 'invalid_request',
                'message' => $this->t('api.error.invalid_json', [], 'Request body must be valid JSON.'),
            ], 400);

            return;
        }

        // Chaîne JSON brute EXACTE reçue du hub — ne JAMAIS la ré-encoder avant la vérif signature.
        $payloadJson = $envelope['payload'];
        $signatureB64 = $envelope['signature'];

        $verifier = $this->getVerifier();

        if (!$verifier->verifySignature($payloadJson, $signatureB64)) {
            $this->recordAuditEvent('security.hubkey_invalid_signature');
            $this->renderJson(['error' => 'invalid_signature', 'message' => 'Invalid signature.'], 401);

            return;
        }

        // Le payload n'est décodé qu'APRÈS vérification réussie de la signature sur la chaîne brute.
        $payload = $verifier->decodePayload($payloadJson);
        if ($payload === null) {
            $this->renderJson(['error' => 'invalid_payload', 'message' => 'Malformed payload.'], 400);

            return;
        }

        $actualShopUrl = $this->getActualShopUrl();
        if (!$verifier->shopUrlMatches($payload['shop_url'], $actualShopUrl)) {
            $this->recordAuditEvent('security.hubkey_domain_mismatch');
            $this->renderJson(['error' => 'domain_mismatch', 'message' => 'shop_url does not match this shop.'], 400);

            return;
        }

        if (!$verifier->isWithinValidityWindow($payload['issued_at'])) {
            $this->recordAuditEvent('security.hubkey_stale_payload');
            $this->renderJson(['error' => 'stale_payload', 'message' => 'issued_at outside validity window.'], 400);

            return;
        }

        // Même emplacement de configuration que la clé de licence hub actuelle (SettingsService).
        $this->getSettingsService()->setHubLicenseKey($payload['license_key']);

        // Trace d'audit SANS la clé (jamais en clair, même en cas d'erreur plus haut).
        $this->recordAuditEvent('push.license_recovered');

        $this->renderJson(['ok' => true], 200);
    }

    private function getActualShopUrl(): string
    {
        $baseDomain = Tools::getShopDomainSsl(true);
        $baseDomain = is_string($baseDomain) ? trim($baseDomain) : '';
        if ($baseDomain === '') {
            return '';
        }

        if (stripos($baseDomain, 'http') !== 0) {
            $baseDomain = 'https://' . ltrim($baseDomain, '/');
        }

        return $baseDomain;
    }

    /**
     * Expose le vérificateur de signature aux sous-classes — seam de test permettant d'injecter
     * une clé publique de test (paire RSA jetable) sans exposer la clé privée réelle du hub.
     */
    protected function getVerifier(): HubKeyVerifier
    {
        if ($this->verifier === null) {
            $this->verifier = new HubKeyVerifier();
        }

        return $this->verifier;
    }
}
