(function () {
    'use strict';

    var container = document.getElementById('rebuildconnector_qr_container');
    if (!container || typeof QRCode === 'undefined') {
        return;
    }

    var configRaw = container.getAttribute('data-config') || '{}';
    var config;

    try {
        config = JSON.parse(configRaw);
    } catch (error) {
        if (window.console && typeof window.console.error === 'function') {
            console.error('[RebuildConnector] Unable to parse QR configuration payload.', error);
        }
        return;
    }

    var apiKeyInput = document.getElementById('rebuildconnector_api_key');
    var qrTarget = container.querySelector('[data-role="qr-target"]');
    var refreshButton = document.getElementById('rebuildconnector_qr_refresh');
    var statusLabel = document.querySelector('[data-role="qr-status"]');

    if (!apiKeyInput || !qrTarget) {
        return;
    }

    var debounceTimer = null;

    function getPayload() {
        var currentApiKey = (apiKeyInput.value || '').trim();
        if (currentApiKey === '' && typeof config.apiKey === 'string') {
            currentApiKey = config.apiKey.trim();
        }

        var version = typeof config.version === 'number' ? config.version : parseInt(config.version || '1', 10);
        if (!isFinite(version) || version <= 0) {
            version = 1;
        }

        return {
            module: config.module || 'rebuildconnector',
            version: version,
            shopUrl: config.shopUrl || config.shop_url || '',
            apiKey: currentApiKey,
            api_base_url: config.api_base_url || '',
            api_legacy_url: config.api_legacy_url || '',
            generated_at: new Date().toISOString()
        };
    }

    function updateStatusLabel() {
        if (!statusLabel) {
            return;
        }

        var now = new Date();
        statusLabel.textContent = now.toLocaleString();
    }

    function renderQrCode() {
        var payload = JSON.stringify(getPayload());

        while (qrTarget.firstChild) {
            qrTarget.removeChild(qrTarget.firstChild);
        }

        new QRCode(qrTarget, {
            text: payload,
            width: 200,
            height: 200,
            correctLevel: QRCode.CorrectLevel.M
        });

        updateStatusLabel();
    }

    function scheduleRender() {
        if (debounceTimer !== null) {
            window.clearTimeout(debounceTimer);
        }

        debounceTimer = window.setTimeout(renderQrCode, 250);
    }

    renderQrCode();

    apiKeyInput.addEventListener('input', scheduleRender);

    if (refreshButton) {
        refreshButton.addEventListener('click', function (event) {
            event.preventDefault();
            renderQrCode();
        });
    }
})();
