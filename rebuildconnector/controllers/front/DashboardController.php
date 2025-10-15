<?php

defined('_PS_VERSION_') || exit;

class RebuildconnectorDashboardModuleFrontController extends RebuildconnectorBaseApiModuleFrontController
{
    public function initContent(): void
    {
        parent::initContent();

        $this->renderJson([
            'status' => 'ok',
            'message' => 'Dashboard metrics endpoint placeholder.',
        ]);
    }
}
