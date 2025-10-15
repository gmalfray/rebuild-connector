<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorOrdersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $this->renderJson([
            'status' => 'ok',
            'message' => 'Orders endpoint placeholder.',
        ]);
    }
}
