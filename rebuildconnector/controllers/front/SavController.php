<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/SavService.php';

/**
 * SAV natif PrestaShop (`ps_customer_thread` / `ps_customer_message`). Voir `SavService` pour le
 * contrat des statuts de fil et `docs/api.md` pour le détail des routes.
 *
 * ⚠️ `POST .../sav/{id}/reply` envoie un VRAI e-mail à la cliente — jamais appelé depuis un test
 * automatisé contre des données réelles (cf. mandat de tâche).
 */
class RebuildconnectorSavModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?SavService $savService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            switch ($method) {
                case 'GET':
                    $this->requireAuth(['sav.read']);
                    $this->handleGet();
                    break;
                case 'PATCH':
                    $authPayload = $this->requireAuth(['sav.write']);
                    $this->handlePatch($authPayload);
                    break;
                case 'POST':
                    $authPayload = $this->requireAuth(['sav.write']);
                    $this->handlePost($authPayload);
                    break;
                default:
                    // @ : header() peut émettre un warning "headers already sent" hors contexte HTTP réel
                    // (ex. exécution CLI PHPUnit où du texte a déjà été écrit sur stdout) ; sans impact
                    // en production (le header Allow est informatif sur une 405).
                    @header('Allow: GET, PATCH, POST');
                    $this->jsonError(
                        'method_not_allowed',
                        $this->t('api.error.method_not_allowed', [], 'HTTP method not allowed.'),
                        405
                    );
                    return;
            }
        } catch (AuthenticationException $exception) {
            $this->jsonError(
                'unauthenticated',
                $this->t('api.error.unauthenticated', [], 'Authentication required.'),
                401
            );
        } catch (AuthorizationException $exception) {
            $this->jsonError(
                'forbidden',
                $this->t('api.error.forbidden', [], 'You do not have the required permissions.'),
                403
            );
        } catch (\InvalidArgumentException $exception) {
            $this->jsonError(
                'invalid_payload',
                $exception->getMessage(),
                400
            );
        } catch (\Throwable $exception) {
            $message = $this->isDevMode() ? $exception->getMessage() : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleGet(): void
    {
        // /sav/stats — déclarée avant /sav/{id} dans hookModuleRoutes() (même convention que
        // /customers/stats), donc jamais atteinte avec un id numérique porté par l'URL. Compteur
        // exact « à traiter » (voir SavService), indépendant de la pagination.
        if (Tools::getValue('action') === 'stats') {
            $this->renderJson([
                'to_process' => $this->getSavService()->getToProcessCount(),
            ]);
            return;
        }

        $idRaw = Tools::getValue('id_customer_thread', Tools::getValue('id', false));
        $hasIdSegment = ($idRaw !== false && $idRaw !== '' && $idRaw !== null);
        $threadId = (int) $idRaw;

        // /sav/{id} avec un id non valide (ex. /sav/0) → 404, au lieu de retomber sur la liste.
        if ($hasIdSegment && $threadId <= 0) {
            $this->jsonError('not_found', $this->t('sav.error.not_found', [], 'Thread not found.'), 404);
            return;
        }

        if ($threadId > 0) {
            $result = $this->getSavService()->getThreadById($threadId);
            if ($result === null) {
                $this->jsonError('not_found', $this->t('sav.error.not_found', [], 'Thread not found.'), 404);
                return;
            }

            $this->renderJson($result);
            return;
        }

        $filters = [
            'limit' => Tools::getValue('limit'),
            'offset' => Tools::getValue('offset'),
        ];

        $status = Tools::getValue('status');
        if (is_string($status) && $status !== '') {
            if (!in_array($status, SavService::VALID_STATUSES, true)) {
                throw new \InvalidArgumentException(
                    $this->t('sav.error.invalid_status', [], 'Unknown thread status filter.')
                );
            }
            $filters['status'] = $status;
        }

        $toProcess = Tools::getValue('to_process');
        if (is_string($toProcess) && in_array($toProcess, ['1', 'true'], true)) {
            $filters['to_process'] = true;
        }

        $result = $this->getSavService()->getThreads($filters);

        $this->renderJson([
            'threads' => $result['items'],
            'pagination' => $result['pagination'],
        ]);
    }

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePatch(array $authPayload): void
    {
        $threadId = (int) Tools::getValue('id', 0);
        if ($threadId <= 0) {
            $this->jsonError('not_found', $this->t('sav.error.not_found', [], 'Thread not found.'), 404);
            return;
        }

        $action = Tools::strtolower((string) Tools::getValue('action', ''));
        if ($action !== 'status') {
            throw new \InvalidArgumentException($this->t('sav.error.invalid_action', [], 'Unsupported thread action.'));
        }

        $payload = $this->decodeRequestBody();
        $status = isset($payload['status']) ? trim((string) $payload['status']) : '';
        if ($status === '') {
            throw new \InvalidArgumentException($this->t('sav.error.status_required', [], 'A valid status is required.'));
        }

        $updatedThread = $this->getSavService()->changeStatus($threadId, $status);
        if ($updatedThread === null) {
            $this->jsonError('not_found', $this->t('sav.error.not_found', [], 'Thread not found.'), 404);
            return;
        }

        $this->recordAuditEvent('sav.status.updated', [
            'thread_id' => $threadId,
            'status' => $status,
            'token_subject' => $authPayload['sub'] ?? null,
        ]);
        $this->dispatchWebhookEvent('sav.status.updated', [
            'thread_id' => (string) $threadId,
            'status' => $status,
        ]);

        $this->renderNoContent();
    }

    /**
     * @param array<string, mixed> $authPayload
     */
    private function handlePost(array $authPayload): void
    {
        $threadId = (int) Tools::getValue('id', 0);
        if ($threadId <= 0) {
            $this->jsonError('not_found', $this->t('sav.error.not_found', [], 'Thread not found.'), 404);
            return;
        }

        $action = Tools::strtolower((string) Tools::getValue('action', ''));
        if ($action !== 'reply') {
            throw new \InvalidArgumentException($this->t('sav.error.invalid_action', [], 'Unsupported thread action.'));
        }

        $payload = $this->decodeRequestBody();
        $message = isset($payload['message']) ? (string) $payload['message'] : '';

        $idEmployee = isset($authPayload['id_employee'])
            ? (int) $authPayload['id_employee']
            : null;

        $result = $this->getSavService()->reply(
            $threadId,
            $message,
            $idEmployee,
            (string) ($this->getClientIp() ?? ''),
            (string) ($_SERVER['HTTP_USER_AGENT'] ?? 'PrestaFlow')
        );
        if ($result === null) {
            $this->jsonError('not_found', $this->t('sav.error.not_found', [], 'Thread not found.'), 404);
            return;
        }

        // Jamais le contenu du message ni l'e-mail de la cliente dans l'audit (donnée client en
        // clair interdite dans les logs) — uniquement des identifiants.
        $this->recordAuditEvent('sav.reply.sent', [
            'thread_id' => $threadId,
            'email_sent' => $result['email_sent'],
            'token_subject' => $authPayload['sub'] ?? null,
        ]);
        $this->dispatchWebhookEvent('sav.reply.sent', [
            'thread_id' => (string) $threadId,
        ]);

        $this->renderJson([
            'thread' => $result['thread'],
            'message' => $result['message'],
            'email_sent' => $result['email_sent'],
        ], 201);
    }

    private function getSavService(): SavService
    {
        if ($this->savService === null) {
            $this->savService = new SavService();
        }

        return $this->savService;
    }
}
