<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/CapabilitiesService.php';

/**
 * GET /api/connector/capabilities — ce que CETTE boutique sait faire (indépendamment des scopes
 * du jeton). Voir `CapabilitiesService` pour la distinction capacité/scope.
 *
 * Auth : jeton valide requis, mais AUCUN scope particulier. Justification (cf. rapport de tâche) :
 * une capacité n'est pas une donnée métier gardée par un scope — c'est un pré-requis que l'app doit
 * pouvoir lire quels que soient les scopes du jeton, précisément pour décider quelles sections
 * gardées par un scope proposer. La gater derrière un scope forcerait toute installation à accorder
 * un scope arbitraire juste pour savoir ce qui existe — circulaire.
 */
class RebuildconnectorCapabilitiesModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?CapabilitiesService $capabilitiesService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            if ($method !== 'GET') {
                // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
                // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
                // en production (le header Allow est informatif sur une 405).
                @header('Allow: GET');
                $this->jsonError(
                    'method_not_allowed',
                    $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                    405
                );
                return;
            }

            $this->requireAuth();
            $this->renderJson($this->getCapabilitiesService()->getCapabilities());
        } catch (AuthenticationException $exception) {
            $this->jsonError(
                'unauthenticated',
                $this->t('api.error.unauthenticated', [], 'Authentication required.'),
                401
            );
        } catch (\Throwable $exception) {
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function getCapabilitiesService(): CapabilitiesService
    {
        if ($this->capabilitiesService === null) {
            $this->capabilitiesService = new CapabilitiesService();
        }

        return $this->capabilitiesService;
    }
}
