<?php

defined('_PS_VERSION_') || exit;

/**
 * Surveillance du tunnel de paiement : détecte une PANNE d'encaissement et déclenche
 * l'événement push `shop.payment.error`.
 *
 * Pourquoi ça vit ici et pas dans un script serveur : le 07→09/08/2026, la boutique
 * pensebonheur.fr est restée 40 h sans pouvoir encaisser sans que rien ne remonte. Le front
 * répondait 200, les commandes gratuites passaient, le back-office était vert : la seule trace
 * de la panne était le journal du module de paiement. Un script de cron sur le serveur aurait
 * réglé le cas de cette boutique-là ; le connecteur étant distribué à des boutiques dont on
 * n'administre pas l'hébergement, la détection doit voyager avec le module.
 *
 * RÈGLE : on alerte sur ce qui relève de la BOUTIQUE, jamais sur l'incident d'un client.
 *   - Un client qui abandonne          → journalisé en INFO par ps_checkout : jamais vu ici.
 *   - Carte refusée, 3-D Secure échoué → ligne ERROR, mais ça le regarde : IGNORÉ, sauf si
 *                                        plusieurs paniers sont touchés (cf. niveau 2).
 *   - Exception SQL/PHP, panne d'auth
 *     PayPal, 5xx du prestataire       → la boutique ne peut plus encaisser : ALERTE.
 *
 * Deux niveaux de détection :
 *   1. Signature technique → alerte immédiate, une occurrence suffit.
 *   2. Volume anormal      → VOLUME_CARTS paniers distincts en échec dans VOLUME_WINDOW, quelle
 *                            que soit la cause. Filet pour une panne d'un genre imprévu ; par
 *                            construction, un client isolé ne peut pas le déclencher.
 *
 * Limite assumée : un détecteur qui vit dans la boutique ne peut pas constater que la boutique
 * est tombée (PHP mort, base injoignable). Ce cas relève d'une surveillance externe.
 */
class PaymentWatchService
{
    /** Clé de configuration portant l'état (JSON) : offset lu, alertes, paniers en échec. */
    public const CONF_STATE = 'REBUILDCONNECTOR_PAYWATCH';

    /** Intervalle minimal entre deux inspections du journal (le hook passe à chaque requête). */
    public const THROTTLE_SECONDS = 300;

    /** Délai minimal entre deux notifications, pour ne pas marteler pendant une panne longue. */
    public const COOLDOWN_SECONDS = 1800;

    /** Nombre de paniers distincts en échec déclenchant le filet volumétrique. */
    public const VOLUME_CARTS = 3;

    /** Fenêtre glissante du filet volumétrique, en secondes. */
    public const VOLUME_WINDOW = 3600;

    /** Taille maximale lue en une passe : borne la mémoire si le journal explose. */
    public const MAX_READ_BYTES = 1048576;

    public const KIND_TECHNICAL = 'technical';
    public const KIND_VOLUME = 'volume';
    public const KIND_NONE = 'none';

    /**
     * Signatures « c'est nous » : exceptions PHP/SQL, indisponibilité ou refus d'authentification
     * du prestataire. Aucune ne peut être provoquée par le moyen de paiement d'un client.
     */
    private const TECHNICAL_PATTERN = '/SQLSTATE|PrestaShopException|PrestaShopDatabaseException|PDOException|Fatal error|Call to undefined|Allowed memory size|Exception \d+|oauth_failed|invalid_client|AUTHENTICATION_FAILURE|NOT_AUTHORIZED|INTERNAL_SERVER_ERROR|SERVICE_UNAVAILABLE|cURL error|Could not resolve host|Connection timed out/i';

    /**
     * Bruit connu, sans effet sur l'encaissement : l'envoi du suivi colis au prestataire échoue
     * régulièrement sur des boutiques parfaitement saines. Ne doit alerter à aucun niveau.
     */
    private const NOISE_PATTERN = '/ADD API call failed/i';

    /** Journal inspecté, relatif à la racine PrestaShop. `%s` = date du jour (Y-m-d). */
    private const LOG_PATTERN = 'var/logs/ps_checkout-1-%s';

    /**
     * Extrait les lignes en erreur d'un fragment de journal, hors bruit connu.
     * Gère les deux formats successifs de ps_checkout (JSON récent, texte plus ancien).
     *
     * @return array<int, string>
     */
    public function extractErrorLines(string $chunk): array
    {
        $lines = preg_split('/\r\n|\r|\n/', $chunk) ?: [];
        $errors = [];

        foreach ($lines as $line) {
            if ($line === '') {
                continue;
            }
            $isError = strpos($line, '"level_name":"ERROR"') !== false
                || strpos($line, 'ps_checkout.ERROR') !== false;
            if (!$isError) {
                continue;
            }
            if (preg_match(self::NOISE_PATTERN, $line) === 1) {
                continue;
            }
            $errors[] = $line;
        }

        return $errors;
    }

    /**
     * Analyse les lignes en erreur : combien relèvent d'une panne technique, quels paniers sont
     * touchés, et quel message afficher. Fonction pure : c'est la partie testable du service.
     *
     * @param array<int, string> $errorLines
     *
     * @return array{errors: int, technical: int, carts: array<int, int>, reason: string}
     */
    public function analyse(array $errorLines): array
    {
        $technical = 0;
        $carts = [];
        $reason = '';
        $technicalReason = '';

        foreach ($errorLines as $line) {
            if (preg_match(self::TECHNICAL_PATTERN, $line) === 1) {
                ++$technical;
            }

            if (preg_match('/"id_cart":(\d+)/', $line, $m) === 1) {
                $carts[(int) $m[1]] = true;
            }

            // Une même ligne porte plusieurs messages emboîtés : l'enveloppe
            // (« CreateOrder - Exception 42 ») puis la cause réelle (l'erreur SQL). On les
            // parcourt tous et on privilégie celui qui porte une signature technique : c'est le
            // seul qui dise quelque chose d'actionnable dans une notification.
            if (preg_match_all('/"(?:error|message)":"([^"]+)"/', $line, $all) >= 1) {
                foreach ($all[1] as $candidate) {
                    if ($candidate === '' || $candidate === 'null') {
                        continue;
                    }
                    $reason = $candidate;
                    if (preg_match(self::TECHNICAL_PATTERN, $candidate) === 1) {
                        $technicalReason = $candidate;
                    }
                }
            }
        }

        if ($technicalReason !== '') {
            $reason = $technicalReason;
        }

        return [
            'errors' => count($errorLines),
            'technical' => $technical,
            'carts' => array_map('intval', array_keys($carts)),
            'reason' => Tools::substr($reason, 0, 140),
        ];
    }

    /**
     * Décide s'il faut alerter, et à quel titre. Fonction pure.
     *
     * @param int $technical      nombre d'erreurs à signature technique sur la passe
     * @param int $cartsInWindow  paniers distincts en échec sur la fenêtre glissante
     * @param int $lastAlertAt    horodatage de la dernière notification (0 si jamais)
     * @param int $now            horodatage courant
     */
    public function decide(int $technical, int $cartsInWindow, int $lastAlertAt, int $now): string
    {
        $candidate = self::KIND_NONE;

        if ($technical > 0) {
            $candidate = self::KIND_TECHNICAL;
        } elseif ($cartsInWindow >= self::VOLUME_CARTS) {
            $candidate = self::KIND_VOLUME;
        }

        if ($candidate === self::KIND_NONE) {
            return self::KIND_NONE;
        }

        // Panne longue : on ne notifie pas à chaque passage, sinon le téléphone devient inutile.
        if ($lastAlertAt > 0 && ($now - $lastAlertAt) < self::COOLDOWN_SECONDS) {
            return self::KIND_NONE;
        }

        return $candidate;
    }

    /**
     * Purge les paniers sortis de la fenêtre glissante et fusionne les nouveaux.
     * Fonction pure.
     *
     * @param array<int, array{cart: int, at: int}> $known
     * @param array<int, int>                       $newCarts
     *
     * @return array<int, array{cart: int, at: int}>
     */
    public function mergeFailingCarts(array $known, array $newCarts, int $now): array
    {
        $kept = [];
        $seen = [];

        foreach ($known as $entry) {
            if (!isset($entry['cart'], $entry['at'])) {
                continue;
            }
            if (($now - (int) $entry['at']) > self::VOLUME_WINDOW) {
                continue;
            }
            $kept[] = ['cart' => (int) $entry['cart'], 'at' => (int) $entry['at']];
            $seen[(int) $entry['cart']] = true;
        }

        foreach ($newCarts as $cart) {
            if (isset($seen[(int) $cart])) {
                continue;
            }
            $kept[] = ['cart' => (int) $cart, 'at' => $now];
            $seen[(int) $cart] = true;
        }

        return $kept;
    }

    /**
     * Inspecte le journal si l'intervalle d'étranglement est écoulé, et notifie s'il y a lieu.
     *
     * @param callable(string, array<string, mixed>): void $notifier reçoit (kind, contexte)
     *
     * @return string le verdict de la passe (KIND_* ; KIND_NONE si rien ou passe étranglée)
     */
    public function run(callable $notifier, ?int $now = null): string
    {
        $now = $now ?? time();
        $state = $this->readState();

        if (($now - (int) $state['checked_at']) < self::THROTTLE_SECONDS) {
            return self::KIND_NONE;
        }

        $today = date('Y-m-d', $now);
        $path = rtrim(_PS_ROOT_DIR_, '/') . '/' . sprintf(self::LOG_PATTERN, $today);

        $state['checked_at'] = $now;

        if (!is_readable($path)) {
            // Pas de journal aujourd'hui = aucun échec de paiement à ce stade.
            $this->writeState($state);

            return self::KIND_NONE;
        }

        $size = (int) @filesize($path);
        $offset = (int) $state['offset'];

        // Changement de jour, rotation ou troncature : on repart du début du fichier courant.
        if ($state['log_date'] !== $today || $size < $offset) {
            $offset = 0;
            $state['log_date'] = $today;
        }

        // Toute première passe : on se cale sur la fin sans rejouer l'historique de la journée.
        if ($state['initialised'] === false) {
            $state['initialised'] = true;
            $state['offset'] = $size;
            $this->writeState($state);

            return self::KIND_NONE;
        }

        if ($size <= $offset) {
            $state['offset'] = $size;
            $this->writeState($state);

            return self::KIND_NONE;
        }

        $chunk = $this->readChunk($path, $offset, $size);
        $state['offset'] = $size;

        $errorLines = $this->extractErrorLines($chunk);
        if ($errorLines === []) {
            $this->writeState($state);

            return self::KIND_NONE;
        }

        $analysis = $this->analyse($errorLines);
        $state['carts'] = $this->mergeFailingCarts($state['carts'], $analysis['carts'], $now);
        $cartsInWindow = count($state['carts']);

        $kind = $this->decide($analysis['technical'], $cartsInWindow, (int) $state['alerted_at'], $now);

        if ($kind !== self::KIND_NONE) {
            $state['alerted_at'] = $now;
            $this->writeState($state);

            $notifier($kind, [
                'errors' => $analysis['errors'],
                'technical' => $analysis['technical'],
                'carts' => $cartsInWindow,
                'reason' => $analysis['reason'],
            ]);

            return $kind;
        }

        $this->writeState($state);

        return self::KIND_NONE;
    }

    /**
     * Lit l'incrément du journal, borné par MAX_READ_BYTES (on garde la FIN du fragment : en cas
     * de cascade, les dernières lignes sont les plus représentatives de l'état courant).
     */
    protected function readChunk(string $path, int $offset, int $size): string
    {
        $length = $size - $offset;
        if ($length > self::MAX_READ_BYTES) {
            $offset = $size - self::MAX_READ_BYTES;
            $length = self::MAX_READ_BYTES;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return '';
        }

        @fseek($handle, $offset);
        $chunk = @fread($handle, $length);
        @fclose($handle);

        return $chunk === false ? '' : $chunk;
    }

    /**
     * @return array{offset: int, log_date: string, checked_at: int, alerted_at: int, initialised: bool, carts: array<int, array{cart: int, at: int}>}
     */
    public function readState(): array
    {
        $raw = Configuration::get(self::CONF_STATE);
        $decoded = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'offset' => isset($decoded['offset']) ? (int) $decoded['offset'] : 0,
            'log_date' => isset($decoded['log_date']) && is_string($decoded['log_date']) ? $decoded['log_date'] : '',
            'checked_at' => isset($decoded['checked_at']) ? (int) $decoded['checked_at'] : 0,
            'alerted_at' => isset($decoded['alerted_at']) ? (int) $decoded['alerted_at'] : 0,
            'initialised' => isset($decoded['initialised']) ? (bool) $decoded['initialised'] : false,
            'carts' => isset($decoded['carts']) && is_array($decoded['carts']) ? $decoded['carts'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $state
     */
    protected function writeState(array $state): void
    {
        $encoded = json_encode($state);
        Configuration::updateValue(self::CONF_STATE, $encoded === false ? '{}' : $encoded);
    }
}
