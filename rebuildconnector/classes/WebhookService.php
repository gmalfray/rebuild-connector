<?php

defined('_PS_VERSION_') || exit;

class WebhookService
{
    private SettingsService $settingsService;

    public function __construct(?SettingsService $settingsService = null)
    {
        $this->settingsService = $settingsService ?: new SettingsService();
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function dispatch(string $event, array $payload = []): bool
    {
        $url = trim($this->settingsService->getWebhookUrl());
        if ($url === '') {
            return false;
        }

        $bodyArray = [
            'event' => $event,
            'emitted_at' => date(DATE_ATOM),
            'data' => $payload,
        ];

        $body = json_encode($bodyArray, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return false;
        }

        $headers = ['Content-Type: application/json'];

        $secret = $this->settingsService->getWebhookSecret();
        if ($secret !== '') {
            $signature = hash_hmac('sha256', $body, $secret);
            $headers[] = 'X-RebuildConnector-Signature: ' . $signature;
        }

        if (!function_exists('curl_init')) {
            $this->log('cURL extension is required to send webhooks.');
            return false;
        }

        $handle = curl_init($url);
        if ($handle === false) {
            $this->log('Unable to initialize webhook request.');
            return false;
        }

        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($handle, CURLOPT_TIMEOUT, 10);

        $response = curl_exec($handle);
        $status = (int) curl_getinfo($handle, CURLINFO_HTTP_CODE);

        if ($response === false) {
            $this->log('Webhook dispatch failed: ' . curl_error($handle));
            curl_close($handle);
            return false;
        }

        curl_close($handle);

        if ($status < 200 || $status >= 300) {
            $this->log(sprintf('Webhook returned HTTP %d', $status));
            return false;
        }

        return true;
    }

    private function log(string $message): void
    {
        if (!$this->isDevMode()) {
            return;
        }

        error_log('[RebuildConnector] Webhook: ' . $message);
    }

    private function isDevMode(): bool
    {
        if (!defined('_PS_MODE_DEV_')) {
            return false;
        }

        return (bool) constant('_PS_MODE_DEV_');
    }
}
