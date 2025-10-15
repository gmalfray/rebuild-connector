<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorProductsModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $this->renderJson([
            'status' => 'ok',
            'message' => 'Products endpoint placeholder.',
        ]);
    }
}
