<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorApiModuleFrontController extends ModuleFrontController
{
    protected $ssl = true;
    protected $display_header = false;
    protected $display_footer = false;

    public function initContent()
    {
        parent::initContent();

        $this->renderJson([
            'status' => 'ok',
            'message' => 'Authentication endpoint placeholder.',
        ]);
    }

    protected function renderJson(array $payload, int $statusCode = 200)
    {
        header('Content-Type: application/json');
        http_response_code($statusCode);
        $this->ajaxRender(json_encode($payload));
    }
}
