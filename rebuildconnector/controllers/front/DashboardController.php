<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorDashboardModuleFrontController extends ModuleFrontController
{
    /** @var bool */
    protected $ssl = true;
    /** @var bool */
    protected $display_header = false;
    /** @var bool */
    protected $display_footer = false;

    public function initContent(): void
    {
        parent::initContent();

        $this->renderJson([
            'status' => 'ok',
            'message' => 'Dashboard metrics endpoint placeholder.',
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     */
    protected function renderJson(array $payload, int $statusCode = 200): void
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        $body = json_encode($payload);
        $this->ajaxRender($body === false ? '{}' : $body);
    }
}
