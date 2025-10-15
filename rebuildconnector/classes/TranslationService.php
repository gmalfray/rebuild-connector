<?php

defined('_PS_VERSION_') || exit;

class TranslationService
{
    /**
     * @var array<string, array<string, string>>
     */
    private const STRINGS = [
        // Module metadata
        'module.name' => [
            'en' => 'Rebuild Connector',
            'fr' => 'Rebuild Connector',
        ],
        'module.description' => [
            'en' => 'API connector between PrestaShop and the PrestaFlow mobile application.',
            'fr' => 'Connecteur API entre PrestaShop et l’application mobile PrestaFlow.',
        ],

        // Admin messages & errors
        'admin.message.secret_regenerated' => [
            'en' => 'JWT secret regenerated. Update mobile clients to keep them in sync.',
            'fr' => 'Secret JWT régénéré. Mettez à jour les clients mobiles pour rester synchrones.',
        ],
        'admin.message.settings_updated' => [
            'en' => 'Settings updated.',
            'fr' => 'Paramètres mis à jour.',
        ],
        'admin.error.api_key_empty' => [
            'en' => 'API key cannot be empty.',
            'fr' => 'La clé API ne peut pas être vide.',
        ],
        'admin.error.invalid_service_account' => [
            'en' => 'The FCM service account must contain valid JSON.',
            'fr' => 'Le compte de service FCM doit contenir un JSON valide.',
        ],

        // Admin form labels & help
        'admin.form.title' => [
            'en' => 'Rebuild Connector — API & Notifications',
            'fr' => 'Rebuild Connector — API & notifications',
        ],
        'admin.form.api_key_label' => [
            'en' => 'API key',
            'fr' => 'Clé API',
        ],
        'admin.form.api_key_help' => [
            'en' => 'Key dedicated to the PrestaFlow mobile app. Share it securely with your team only.',
            'fr' => 'Clé dédiée à l’application mobile PrestaFlow. Partagez-la uniquement au sein de votre équipe, de façon sécurisée.',
        ],
        'admin.form.token_ttl_label' => [
            'en' => 'Token lifetime (seconds)',
            'fr' => 'Durée de vie du jeton (secondes)',
        ],
        'admin.form.token_ttl_help' => [
            'en' => 'Duration before access tokens expire. Default: 3600 seconds (1 hour).',
            'fr' => 'Durée avant l’expiration des jetons d’accès. Valeur par défaut : 3600 secondes (1 heure).',
        ],
        'admin.form.scopes_label' => [
            'en' => 'Authorized scopes',
            'fr' => 'Scopes autorisés',
        ],
        'admin.form.scopes_help' => [
            'en' => 'One scope per line (e.g. orders.read, products.write). Leave empty to restore defaults.',
            'fr' => 'Un scope par ligne (ex. orders.read, products.write). Laissez vide pour restaurer les valeurs par défaut.',
        ],
        'admin.form.jwt_label' => [
            'en' => 'JWT secret',
            'fr' => 'Secret JWT',
        ],
        'admin.form.jwt_help' => [
            'en' => 'Used to sign the tokens sent to the mobile app. Regenerate if you suspect a leak.',
            'fr' => 'Utilisé pour signer les jetons envoyés à l’application mobile. Régénérez-le en cas de suspicion de fuite.',
        ],
        'admin.form.regenerate_button' => [
            'en' => 'Regenerate secret',
            'fr' => 'Régénérer le secret',
        ],
        'admin.form.regenerate_confirm' => [
            'en' => 'Regenerating the secret invalidates all current sessions. Continue?',
            'fr' => 'La régénération du secret invalide toutes les sessions en cours. Continuer ?',
        ],
        'admin.form.service_account_label' => [
            'en' => 'FCM service account (JSON)',
            'fr' => 'Compte de service FCM (JSON)',
        ],
        'admin.form.service_account_help' => [
            'en' => 'Paste the JSON content of your Firebase service account used for HTTP v1 notifications.',
            'fr' => 'Collez le contenu JSON de votre compte de service Firebase utilisé pour les notifications HTTP v1.',
        ],
        'admin.form.device_tokens_label' => [
            'en' => 'Fallback device tokens',
            'fr' => 'Jetons d’appareils de secours',
        ],
        'admin.form.device_tokens_help' => [
            'en' => 'Optional static tokens (one per line) used until the app registers users automatically.',
            'fr' => 'Jetons statiques optionnels (un par ligne) utilisés jusqu’à l’enregistrement automatique des appareils par l’application.',
        ],
        'admin.form.save_button' => [
            'en' => 'Save settings',
            'fr' => 'Enregistrer les paramètres',
        ],

        // API & base controller errors
        'api.error.method_not_allowed' => [
            'en' => 'This endpoint only accepts POST requests.',
            'fr' => 'Cette route accepte uniquement les requêtes POST.',
        ],
        'api.error.api_key_required' => [
            'en' => 'The api_key field is required.',
            'fr' => 'Le champ api_key est obligatoire.',
        ],
        'api.error.auth_failed' => [
            'en' => 'Authentication failed. Check your API key.',
            'fr' => 'Authentification échouée. Vérifiez votre clé API.',
        ],
        'api.error.unexpected' => [
            'en' => 'Unexpected error during authentication.',
            'fr' => 'Erreur inattendue lors de l’authentification.',
        ],
        'api.error.read_body' => [
            'en' => 'Unable to read request body.',
            'fr' => 'Impossible de lire le corps de la requête.',
        ],
        'api.error.invalid_json' => [
            'en' => 'Request body must be valid JSON.',
            'fr' => 'Le corps de la requête doit être un JSON valide.',
        ],

        // Notifications
        'notifications.order_new_title' => [
            'en' => 'New order received',
            'fr' => 'Nouvelle commande reçue',
        ],
        'notifications.order_status_title' => [
            'en' => 'Order status updated',
            'fr' => 'Statut de commande mis à jour',
        ],
        'notifications.status_updated_generic' => [
            'en' => 'Status updated',
            'fr' => 'Statut mis à jour',
        ],
        'notifications.order_summary' => [
            'en' => '#%s - %s',
            'fr' => 'Commande #%s - %s',
        ],
    ];

    public function translate(string $key, string $locale, array $parameters = [], ?string $fallback = null): string
    {
        $normalizedLocale = $this->normalizeLocale($locale);
        $translations = self::STRINGS[$key] ?? [];

        $value = $translations[$normalizedLocale] ?? $translations['en'] ?? $fallback ?? $key;

        if ($parameters !== []) {
            $value = vsprintf($value, $parameters);
        }

        return $value;
    }

    /**
     * @return array<string, string>
     */
    public function getAdminFormStrings(string $locale): array
    {
        return [
            'title' => $this->translate('admin.form.title', $locale),
            'api_key_label' => $this->translate('admin.form.api_key_label', $locale),
            'api_key_help' => $this->translate('admin.form.api_key_help', $locale),
            'token_ttl_label' => $this->translate('admin.form.token_ttl_label', $locale),
            'token_ttl_help' => $this->translate('admin.form.token_ttl_help', $locale),
            'scopes_label' => $this->translate('admin.form.scopes_label', $locale),
            'scopes_help' => $this->translate('admin.form.scopes_help', $locale),
            'jwt_label' => $this->translate('admin.form.jwt_label', $locale),
            'jwt_help' => $this->translate('admin.form.jwt_help', $locale),
            'regenerate_button' => $this->translate('admin.form.regenerate_button', $locale),
            'regenerate_confirm' => $this->translate('admin.form.regenerate_confirm', $locale),
            'service_account_label' => $this->translate('admin.form.service_account_label', $locale),
            'service_account_help' => $this->translate('admin.form.service_account_help', $locale),
            'device_tokens_label' => $this->translate('admin.form.device_tokens_label', $locale),
            'device_tokens_help' => $this->translate('admin.form.device_tokens_help', $locale),
            'save_button' => $this->translate('admin.form.save_button', $locale),
        ];
    }

    private function normalizeLocale(string $locale): string
    {
        $locale = strtolower($locale);
        $parts = explode('-', $locale);

        return $parts[0] !== '' ? $parts[0] : 'en';
    }
}
