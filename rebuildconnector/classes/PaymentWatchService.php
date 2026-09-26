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
 *                                        plusieurs paniers sont touchés (cf. niveau 3).
 *   - Exception SQL/PHP, 5xx du prestataire, échec de NOTRE config OAuth → la boutique ne peut
 *     plus encaisser : ALERTE immédiate.
 *   - Autorisation PayPal refusée (401) sur un client isolé → le 26/09/2026, une cliente a vu ce
 *     refus 4 fois sur SON SEUL panier avant d'abandonner, pendant que tous les autres paiements
 *     passaient normalement : c'est le jeton `ps_checkout`/PayPal qui vieillit, un incident
 *     ponctuel côté session client, pas une panne boutique. Ça n'alerte que si ça touche
 *     plusieurs paniers ou dure dans le temps (cf. niveau 2).
 *
 * Trois niveaux de détection :
 *   1. Signature dure (SQL/PHP, 5xx prestataire, OAuth boutique) → alerte immédiate, une
 *      occurrence suffit.
 *   2. Autorisation PayPal en échec → alerte seulement si VOLUME_CARTS paniers distincts sont
 *      touchés, ou si le même échec persiste plus de AUTH_PERSIST_SECONDS dans la fenêtre.
 *   3. Volume anormal, cause inconnue → VOLUME_CARTS paniers distincts en échec dans
 *                                        VOLUME_WINDOW. Filet pour une panne d'un genre imprévu ;
 *                                        par construction, un client isolé ne peut pas le
 *                                        déclencher.
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

    /**
     * Durée au-delà de laquelle une autorisation PayPal en échec continu devient une alerte,
     * même sur un seul panier : ce n'est plus un client qui retente, c'est vraisemblablement le
     * jeton de la boutique qui a expiré.
     */
    public const AUTH_PERSIST_SECONDS = 1800;

    /** Taille maximale lue en une passe : borne la mémoire si le journal explose. */
    public const MAX_READ_BYTES = 1048576;

    public const KIND_TECHNICAL = 'technical';
    public const KIND_AUTH = 'auth';
    public const KIND_VOLUME = 'volume';
    public const KIND_NONE = 'none';

    /**
     * Signatures dures « c'est nous » : exceptions PHP/SQL, indisponibilité du prestataire, ou
     * échec de NOTRE configuration OAuth. Aucune ne peut être provoquée par le moyen de paiement
     * d'un client : une seule occurrence suffit à alerter.
     */
    private const HARD_PATTERN = '/SQLSTATE|PrestaShopException|PrestaShopDatabaseException|PDOException|Fatal error|Call to undefined|Allowed memory size|oauth_failed|invalid_client|INTERNAL_SERVER_ERROR|SERVICE_UNAVAILABLE|cURL error|Could not resolve host|Connection timed out/i';

    /**
     * Autorisation PayPal refusée (401) : le jeton `ps_checkout`/PayPal qui vieillit, PAS une
     * carte refusée. Volontairement séparé de HARD_PATTERN : sur un seul panier, une occurrence,
     * même répétée par le même client qui retente, reste un incident transitoire côté session
     * (cf. l'incident du 26/09/2026). Ne compte comme panne boutique que via `decide()`, quand
     * plusieurs paniers sont touchés ou que ça dure.
     */
    private const AUTH_PATTERN = '/could not be authorized|Unauthorized|NOT_AUTHORIZED|AUTHENTICATION_FAILURE|\b401\b/i';

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
     * @return array{errors: int, technical: int, auth: int, carts: array<int, int>, authCarts: array<int, int>, reason: string}
     */
    public function analyse(array $errorLines): array
    {
        $hard = 0;
        $auth = 0;
        $carts = [];
        $authCarts = [];
        $reason = '';
        $hardReason = '';
        $authReason = '';

        foreach ($errorLines as $line) {
            $isHard = preg_match(self::HARD_PATTERN, $line) === 1;
            $isAuth = !$isHard && preg_match(self::AUTH_PATTERN, $line) === 1;

            if ($isHard) {
                ++$hard;
            } elseif ($isAuth) {
                ++$auth;
            }

            if (preg_match('/"id_cart":(\d+)/', $line, $m) === 1) {
                $cartId = (int) $m[1];
                $carts[$cartId] = true;
                if ($isAuth) {
                    $authCarts[$cartId] = true;
                }
            }

            // Une même ligne porte plusieurs messages emboîtés : l'enveloppe
            // (« CreateOrder - Exception 0 ») puis la cause réelle. On les parcourt tous et on
            // privilégie la signature la plus actionnable : dure, puis auth PayPal, puis générique.
            if (preg_match_all('/"(?:error|message)":"([^"]+)"/', $line, $all) >= 1) {
                foreach ($all[1] as $candidate) {
                    if ($candidate === '' || $candidate === 'null') {
                        continue;
                    }
                    $reason = $candidate;
                    if (preg_match(self::HARD_PATTERN, $candidate) === 1) {
                        $hardReason = $candidate;
                    } elseif ($hardReason === '' && preg_match(self::AUTH_PATTERN, $candidate) === 1) {
                        $authReason = $candidate;
                    }
                }
            }
        }

        if ($hardReason !== '') {
            $reason = $hardReason;
        } elseif ($authReason !== '') {
            $reason = $authReason;
        }

        return [
            'errors' => count($errorLines),
            'technical' => $hard,
            'auth' => $auth,
            'carts' => array_map('intval', array_keys($carts)),
            'authCarts' => array_map('intval', array_keys($authCarts)),
            'reason' => Tools::substr($reason, 0, 140),
        ];
    }

    /**
     * Décide s'il faut alerter, et à quel titre. Fonction pure.
     *
     * @param int $hard                nombre d'erreurs à signature dure sur la passe
     * @param int $authCartsInWindow   paniers distincts en échec d'autorisation PayPal sur la fenêtre
     * @param int $authPersistSeconds  durée depuis le plus ancien échec d'autorisation encore dans
     *                                 la fenêtre (0 si aucun)
     * @param int $cartsInWindow       paniers distincts en échec, toute cause confondue, sur la fenêtre
     * @param int $lastAlertAt         horodatage de la dernière notification (0 si jamais)
     * @param int $now                 horodatage courant
     */
    public function decide(
        int $hard,
        int $authCartsInWindow,
        int $authPersistSeconds,
        int $cartsInWindow,
        int $lastAlertAt,
        int $now
    ): string {
        $candidate = self::KIND_NONE;

        if ($hard > 0) {
            $candidate = self::KIND_TECHNICAL;
        } elseif ($authCartsInWindow >= self::VOLUME_CARTS || $authPersistSeconds >= self::AUTH_PERSIST_SECONDS) {
            $candidate = self::KIND_AUTH;
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
        $state['auth_carts'] = $this->mergeFailingCarts($state['auth_carts'], $analysis['authCarts'], $now);

        $cartsInWindow = count($state['carts']);
        $authCartsInWindow = count($state['auth_carts']);
        $authOldestAt = $this->oldestAt($state['auth_carts']);
        $authPersistSeconds = $authOldestAt > 0 ? ($now - $authOldestAt) : 0;

        $kind = $this->decide(
            $analysis['technical'],
            $authCartsInWindow,
            $authPersistSeconds,
            $cartsInWindow,
            (int) $state['alerted_at'],
            $now
        );

        if ($kind !== self::KIND_NONE) {
            $state['alerted_at'] = $now;
            $this->writeState($state);

            $notifier($kind, [
                'errors' => $analysis['errors'],
                'technical' => $analysis['technical'],
                'auth' => $analysis['auth'],
                'carts' => $kind === self::KIND_AUTH ? $authCartsInWindow : $cartsInWindow,
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
     * Plus ancien horodatage encore dans la fenêtre, 0 si la liste est vide. Fonction pure.
     *
     * @param array<int, array{cart: int, at: int}> $entries
     */
    private function oldestAt(array $entries): int
    {
        $oldest = 0;
        foreach ($entries as $entry) {
            $at = isset($entry['at']) ? (int) $entry['at'] : 0;
            if ($oldest === 0 || $at < $oldest) {
                $oldest = $at;
            }
        }

        return $oldest;
    }

    /**
     * @return array{offset: int, log_date: string, checked_at: int, alerted_at: int, initialised: bool, carts: array<int, array{cart: int, at: int}>, auth_carts: array<int, array{cart: int, at: int}>}
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
            'auth_carts' => isset($decoded['auth_carts']) && is_array($decoded['auth_carts']) ? $decoded['auth_carts'] : [],
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
