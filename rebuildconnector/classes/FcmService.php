<?php

defined('_PS_VERSION_') || exit;

class FcmService
{
    /**
     * @param array<string, mixed> $payload
     */
    public function sendNotification(array $payload): bool
    {
        // TODO: push notification via Firebase Cloud Messaging.
        return false;
    }
}
