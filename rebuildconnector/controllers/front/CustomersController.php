<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorCustomersModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $this->renderJson([
            'status' => 'ok',
            'message' => 'Customers endpoint placeholder.',
        ]);
    }
}
