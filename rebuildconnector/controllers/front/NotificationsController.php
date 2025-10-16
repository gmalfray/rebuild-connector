<?php

defined('_PS_VERSION_') || exit;

require_once __DIR__ . '/BaseApiController.php';
require_once _PS_MODULE_DIR_ . 'rebuildconnector/classes/FcmDeviceService.php';

class RebuildconnectorNotificationsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    private ?FcmDeviceService $deviceService = null;

    public function initContent(): void
    {
        parent::initContent();

        $method = Tools::strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

        try {
            switch ($method) {
                case 'POST':
                    $this->requireAuth(['notifications.send']);
                    $this->handleRegister();
                    break;
                case 'DELETE':
                    $this->requireAuth(['notifications.send']);
                    $this->handleUnregister();
                    break;
                default:
                    header('Allow: POST, DELETE');
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
            $message = $this->isDevMode()
                ? $exception->getMessage()
                : $this->t('api.error.unexpected', [], 'Unexpected error occurred.');
            $this->jsonError('server_error', $message, 500);
        }
    }

    private function handleRegister(): void
    {
        $payload = $this->decodeRequestBody();

        $token = isset($payload['token']) ? trim((string) $payload['token']) : '';
        if ($token === '') {
            throw new \InvalidArgumentException(
                $this->t('notifications.error.token_required', [], 'Device token is required.')
            );
        }

        $topics = $this->extractTopics($payload['topics'] ?? null);
        if ($topics === []) {
            $topics = $this->getSettingsService()->getFcmTopics();
        }

        $deviceId = isset($payload['device_id']) ? trim((string) $payload['device_id']) : null;
        $platform = isset($payload['platform']) ? trim((string) $payload['platform']) : null;

        $this->getDeviceService()->registerDevice($token, $topics, $deviceId, $platform);

        $this->renderJson([
            'status' => 'registered',
            'token' => $token,
            'topics' => $topics,
        ], 200);
    }

    private function handleUnregister(): void
    {
        $token = trim((string) Tools::getValue('token', ''));

        if ($token === '') {
            try {
                $payload = $this->decodeRequestBody();
                $token = isset($payload['token']) ? trim((string) $payload['token']) : '';
            } catch (\InvalidArgumentException $exception) {
                $token = '';
            }
        }

        if ($token === '') {
            throw new \InvalidArgumentException(
                $this->t('notifications.error.token_required', [], 'Device token is required.')
            );
        }

        $this->getDeviceService()->unregisterDevice($token);

        header('Content-Type: application/json');
        http_response_code(204);
        $this->ajaxRender('');
    }

    /**
     * @param mixed $topics
     * @return array<int, string>
     */
    private function extractTopics($topics): array
    {
        if (is_string($topics)) {
            $topics = preg_split('/[\r\n,]+/', $topics) ?: [];
        } elseif (!is_array($topics)) {
            $topics = [];
        }

        return FcmDeviceService::sanitizeTopics($topics);
    }

    private function getDeviceService(): FcmDeviceService
    {
        if ($this->deviceService === null) {
            $this->deviceService = new FcmDeviceService();
        }

        return $this->deviceService;
    }
}
