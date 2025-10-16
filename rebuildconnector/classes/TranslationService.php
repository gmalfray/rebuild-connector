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
        'admin.error.invalid_webhook_url' => [
            'en' => 'The webhook URL must be a valid HTTPS address.',
            'fr' => 'L’URL du webhook doit être une adresse HTTPS valide.',
        ],
        'admin.error.invalid_ip_range' => [
            'en' => 'One or more IP ranges are invalid. Use plain IPs or CIDR notation.',
            'fr' => 'Une ou plusieurs adresses IP sont invalides. Utilisez des IPs simples ou en notation CIDR.',
        ],
        'admin.error.invalid_env_overrides' => [
            'en' => 'Environment overrides must follow the KEY=VALUE format (uppercase keys).',
            'fr' => 'Les overrides d’environnement doivent respecter le format KEY=VALUE (clés en majuscules).',
        ],
        'admin.error.invalid_rate_limit' => [
            'en' => 'Rate limit must be a positive integer.',
            'fr' => 'La limite de requêtes doit être un entier positif.',
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
        'admin.form.topics_label' => [
            'en' => 'FCM topics',
            'fr' => 'Topics FCM',
        ],
        'admin.form.topics_help' => [
            'en' => 'Optional list of topics (one per line) used as the primary broadcast channel.',
            'fr' => 'Liste optionnelle de topics (un par ligne) utilisée comme canal principal de diffusion.',
        ],
        'admin.form.device_tokens_label' => [
            'en' => 'Fallback device tokens',
            'fr' => 'Jetons d’appareils de secours',
        ],
        'admin.form.device_tokens_help' => [
            'en' => 'Optional static tokens (one per line) used until the app registers users automatically.',
            'fr' => 'Jetons statiques optionnels (un par ligne) utilisés jusqu’à l’enregistrement automatique des appareils par l’application.',
        ],
        'admin.form.webhook_url_label' => [
            'en' => 'Webhook URL',
            'fr' => 'URL du webhook',
        ],
        'admin.form.webhook_url_help' => [
            'en' => 'Optional HTTPS endpoint that receives real-time events (e.g. order updates).',
            'fr' => 'Endpoint HTTPS optionnel recevant les événements en temps réel (ex. mises à jour commandes).',
        ],
        'admin.form.webhook_secret_label' => [
            'en' => 'Webhook secret',
            'fr' => 'Secret webhook',
        ],
        'admin.form.webhook_secret_help' => [
            'en' => 'Provide a shared secret for HMAC signatures. Leave empty to keep the current secret.',
            'fr' => 'Fournissez un secret partagé pour les signatures HMAC. Laissez vide pour conserver le secret actuel.',
        ],
        'admin.form.webhook_secret_placeholder' => [
            'en' => 'New secret…',
            'fr' => 'Nouveau secret…',
        ],
        'admin.form.webhook_secret_clear_label' => [
            'en' => 'Reset webhook secret',
            'fr' => 'Réinitialiser le secret webhook',
        ],
        'admin.form.rate_limit_enabled_label' => [
            'en' => 'Rate limiting',
            'fr' => 'Limitation de débit',
        ],
        'admin.form.rate_limit_enabled_toggle' => [
            'en' => 'Enable API rate limiting',
            'fr' => 'Activer la limitation de requêtes API',
        ],
        'admin.form.rate_limit_enabled_help' => [
            'en' => 'When enabled, incoming requests exceeding the threshold are rejected with HTTP 429.',
            'fr' => 'Lorsque activé, les requêtes dépassant le seuil sont rejetées avec un HTTP 429.',
        ],
        'admin.form.rate_limit_label' => [
            'en' => 'Requests per minute',
            'fr' => 'Requêtes par minute',
        ],
        'admin.form.rate_limit_help' => [
            'en' => 'Maximum number of API calls allowed per minute from a single token/IP.',
            'fr' => 'Nombre maximal d’appels API autorisés par minute pour un jeton/IP.',
        ],
        'admin.form.allowed_ips_label' => [
            'en' => 'Allowed IP ranges',
            'fr' => 'Plages IP autorisées',
        ],
        'admin.form.allowed_ips_help' => [
            'en' => 'Restrict access to specific IPs or CIDR ranges (one per line). Leave empty to allow all.',
            'fr' => 'Restreignez l’accès à certaines IP ou plages CIDR (une par ligne). Laissez vide pour tout autoriser.',
        ],
        'admin.form.env_overrides_label' => [
            'en' => 'Environment overrides',
            'fr' => 'Overrides d’environnement',
        ],
        'admin.form.env_overrides_help' => [
            'en' => 'Dynamic configuration injected as KEY=VALUE pairs. Lines starting with # are ignored.',
            'fr' => 'Configuration dynamique injectée sous forme de paires KEY=VALUE. Les lignes commençant par # sont ignorées.',
        ],
        'admin.form.save_button' => [
            'en' => 'Save settings',
            'fr' => 'Enregistrer les paramètres',
        ],

        // API & base controller errors
        'api.error.method_not_allowed' => [
            'en' => 'HTTP method not allowed.',
            'fr' => 'Méthode HTTP non autorisée.',
        ],
        'api.error.forbidden_ip' => [
            'en' => 'Access denied from your IP address.',
            'fr' => 'Accès refusé depuis votre adresse IP.',
        ],
        'api.error.api_key_required' => [
            'en' => 'The api_key field is required.',
            'fr' => 'Le champ api_key est obligatoire.',
        ],
        'api.error.auth_failed' => [
            'en' => 'Authentication failed. Check your API key.',
            'fr' => 'Authentification échouée. Vérifiez votre clé API.',
        ],
        'api.error.rate_limited' => [
            'en' => 'Too many requests. Please try again later.',
            'fr' => 'Trop de requêtes. Veuillez réessayer plus tard.',
        ],
        'api.error.unexpected' => [
            'en' => 'Unexpected error occurred.',
            'fr' => 'Une erreur inattendue s’est produite.',
        ],
        'api.error.read_body' => [
            'en' => 'Unable to read request body.',
            'fr' => 'Impossible de lire le corps de la requête.',
        ],
        'api.error.invalid_json' => [
            'en' => 'Request body must be valid JSON.',
            'fr' => 'Le corps de la requête doit être un JSON valide.',
        ],
        'api.error.unauthenticated' => [
            'en' => 'Authentication required.',
            'fr' => 'Authentification requise.',
        ],
        'api.error.forbidden' => [
            'en' => 'You do not have the required permissions.',
            'fr' => 'Vous n’avez pas les permissions requises.',
        ],
        'api.error.invalid_payload' => [
            'en' => 'The provided data is invalid.',
            'fr' => 'Les données fournies sont invalides.',
        ],
        'api.error.not_found' => [
            'en' => 'Resource not found.',
            'fr' => 'Ressource introuvable.',
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
        'notifications.error.token_required' => [
            'en' => 'Device token is required.',
            'fr' => 'Le jeton d’appareil est requis.',
        ],
        'notifications.error.topic_required' => [
            'en' => 'At least one topic must be provided.',
            'fr' => 'Au moins un topic doit être fourni.',
        ],
        'orders.error.not_found' => [
            'en' => 'Order not found.',
            'fr' => 'Commande introuvable.',
        ],
        'orders.error.invalid_action' => [
            'en' => 'Unsupported order action.',
            'fr' => 'Action sur la commande non prise en charge.',
        ],
        'orders.error.invalid_status' => [
            'en' => 'A valid status is required.',
            'fr' => 'Un statut valide est requis.',
        ],
        'orders.error.invalid_shipping' => [
            'en' => 'A tracking number is required.',
            'fr' => 'Un numéro de suivi est requis.',
        ],
        'orders.error.invalid_carrier' => [
            'en' => 'The carrier_id field must be a positive integer.',
            'fr' => 'Le champ carrier_id doit être un entier positif.',
        ],
        'products.error.not_found' => [
            'en' => 'Product not found.',
            'fr' => 'Produit introuvable.',
        ],
        'products.error.invalid_payload' => [
            'en' => 'Invalid product payload.',
            'fr' => 'Données produit invalides.',
        ],
        'products.error.invalid_action' => [
            'en' => 'Unsupported product action.',
            'fr' => 'Action produit non prise en charge.',
        ],
        'customers.error.not_found' => [
            'en' => 'Customer not found.',
            'fr' => 'Client introuvable.',
        ],
        'customers.error.invalid_limit' => [
            'en' => 'The limit parameter must be a positive integer.',
            'fr' => 'Le paramètre limit doit être un entier positif.',
        ],
        'customers.error.invalid_offset' => [
            'en' => 'The offset parameter must be a non-negative integer.',
            'fr' => 'Le paramètre offset doit être un entier supérieur ou égal à zéro.',
        ],
        'customers.error.invalid_email' => [
            'en' => 'The email filter must contain a valid email address.',
            'fr' => 'Le filtre email doit contenir une adresse email valide.',
        ],
        'customers.error.invalid_segment' => [
            'en' => 'The provided segment filter is not supported.',
            'fr' => 'Le segment fourni n’est pas pris en charge.',
        ],
        'customers.error.invalid_min_orders' => [
            'en' => 'The min_orders filter must be a positive integer.',
            'fr' => 'Le filtre min_orders doit être un entier positif.',
        ],
        'customers.error.invalid_max_orders' => [
            'en' => 'The max_orders filter must be a positive integer.',
            'fr' => 'Le filtre max_orders doit être un entier positif.',
        ],
        'customers.error.invalid_orders_range' => [
            'en' => 'The min_orders filter cannot be greater than max_orders.',
            'fr' => 'Le filtre min_orders ne peut pas être supérieur à max_orders.',
        ],
        'customers.error.invalid_min_spent' => [
            'en' => 'The min_spent filter must be a positive number.',
            'fr' => 'Le filtre min_spent doit être un nombre positif.',
        ],
        'customers.error.invalid_max_spent' => [
            'en' => 'The max_spent filter must be a positive number.',
            'fr' => 'Le filtre max_spent doit être un nombre positif.',
        ],
        'customers.error.invalid_spent_range' => [
            'en' => 'The min_spent filter cannot be greater than max_spent.',
            'fr' => 'Le filtre min_spent ne peut pas être supérieur à max_spent.',
        ],
        'customers.error.invalid_created_from' => [
            'en' => 'The created_from filter must contain a valid date.',
            'fr' => 'Le filtre created_from doit contenir une date valide.',
        ],
        'customers.error.invalid_created_to' => [
            'en' => 'The created_to filter must contain a valid date.',
            'fr' => 'Le filtre created_to doit contenir une date valide.',
        ],
        'customers.error.invalid_created_range' => [
            'en' => 'The created_from filter cannot be greater than created_to.',
            'fr' => 'Le filtre created_from ne peut pas être supérieur à created_to.',
        ],
        'customers.error.invalid_sort' => [
            'en' => 'The requested sort value is not supported.',
            'fr' => 'La valeur de tri demandée n’est pas prise en charge.',
        ],
        'customers.error.invalid_ids' => [
            'en' => 'The ids filter must be a list of customer identifiers.',
            'fr' => 'Le filtre ids doit être une liste d’identifiants clients.',
        ],
    ];

    /**
     * @param array<int, mixed> $parameters
     */
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
            'topics_label' => $this->translate('admin.form.topics_label', $locale),
            'topics_help' => $this->translate('admin.form.topics_help', $locale),
            'device_tokens_label' => $this->translate('admin.form.device_tokens_label', $locale),
            'device_tokens_help' => $this->translate('admin.form.device_tokens_help', $locale),
            'webhook_url_label' => $this->translate('admin.form.webhook_url_label', $locale),
            'webhook_url_help' => $this->translate('admin.form.webhook_url_help', $locale),
            'webhook_secret_label' => $this->translate('admin.form.webhook_secret_label', $locale),
            'webhook_secret_help' => $this->translate('admin.form.webhook_secret_help', $locale),
            'webhook_secret_placeholder' => $this->translate('admin.form.webhook_secret_placeholder', $locale),
            'webhook_secret_clear_label' => $this->translate('admin.form.webhook_secret_clear_label', $locale),
            'rate_limit_enabled_label' => $this->translate('admin.form.rate_limit_enabled_label', $locale),
            'rate_limit_enabled_toggle' => $this->translate('admin.form.rate_limit_enabled_toggle', $locale),
            'rate_limit_enabled_help' => $this->translate('admin.form.rate_limit_enabled_help', $locale),
            'rate_limit_label' => $this->translate('admin.form.rate_limit_label', $locale),
            'rate_limit_help' => $this->translate('admin.form.rate_limit_help', $locale),
            'allowed_ips_label' => $this->translate('admin.form.allowed_ips_label', $locale),
            'allowed_ips_help' => $this->translate('admin.form.allowed_ips_help', $locale),
            'env_overrides_label' => $this->translate('admin.form.env_overrides_label', $locale),
            'env_overrides_help' => $this->translate('admin.form.env_overrides_help', $locale),
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
