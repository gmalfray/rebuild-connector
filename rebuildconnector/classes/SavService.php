<?php

defined('_PS_VERSION_') || exit;

/**
 * SAV natif PrestaShop (`customer_thread` / `customer_message`) — AUCUN module requis.
 *
 * Contrat des statuts de fil (valeurs natives PrestaShop, `customer_thread.status`, ENUM
 * inchangée depuis 1.6 jusqu'à 8.x) :
 *   - `open`     : fil jamais traité.
 *   - `pending1` : en attente d'une réponse de la CLIENTE (le marchand vient de répondre).
 *   - `pending2` : en attente d'une réponse du MARCHAND (la cliente vient d'écrire/relancer).
 *   - `closed`   : fil clos.
 * « Ouvert » au sens de l'app = tout ce qui n'est PAS `closed` (les 3 autres valeurs) : c'est ce
 * qui correspond aux « 97 fils ouverts » mesurés dans l'étude préalable. Cette classe expose donc
 * un tri « non-clos d'abord », et un filtre `status` qui accepte les 4 valeurs natives.
 *
 * Piège rappelé par le mandat de tâche : `Db::getValue()` pose déjà son propre `LIMIT 1`, ne pas
 * en ajouter un dans les requêtes qui l'utilisent.
 */
class SavService
{
    public const DEFAULT_LIMIT = 20;
    public const MAX_LIMIT = 100;

    /** @var array<int, string> Valeurs natives acceptées pour `status`. */
    public const VALID_STATUSES = ['open', 'pending1', 'pending2', 'closed'];

    /** Statut posé sur un fil après une réponse du marchand : on attend la cliente. */
    private const STATUS_AFTER_REPLY = 'pending1';

    /** Longueur maximale défensive d'un message de réponse (le champ SQL est en TEXT, pas de limite technique). */
    private const REPLY_MAX_LENGTH = 20000;

    /**
     * @param array<string, mixed> $filters {status?: string, limit?: int, offset?: int}
     * @return array{items: array<int, array<string, mixed>>, pagination: array<string, mixed>}
     */
    public function getThreads(array $filters = []): array
    {
        $limit = isset($filters['limit']) ? max(1, (int) $filters['limit']) : self::DEFAULT_LIMIT;
        $limit = min($limit, self::MAX_LIMIT);
        $offset = isset($filters['offset']) ? max(0, (int) $filters['offset']) : 0;

        $idShop = $this->getCurrentShopId();

        $query = new DbQuery();
        $query->select($this->threadSelectFields());
        $query->from('customer_thread', 'ct');
        $query->leftJoin('customer', 'c', 'c.id_customer = ct.id_customer');
        $query->leftJoin('orders', 'o', 'o.id_order = ct.id_order');

        // Protection IDOR : ne jamais lister les fils d'une autre boutique (multiboutique).
        if ($idShop > 0) {
            $query->where('ct.id_shop = ' . $idShop);
        }

        $status = isset($filters['status']) ? (string) $filters['status'] : '';
        if ($status !== '') {
            $query->where('ct.status = "' . pSQL($status) . '"');
        }

        // Non-clos d'abord (la charge quotidienne), puis dernier message le plus récent d'abord.
        $query->orderBy('(ct.status = "closed") ASC, last_message_at DESC, ct.id_customer_thread DESC');
        $query->limit($limit + 1, $offset);

        $rows = Db::getInstance()->executeS($query);
        $rows = is_array($rows) ? $rows : [];

        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }

        $threads = [];
        foreach ($rows as $row) {
            /** @var array<string, mixed> $row */
            $threads[] = $this->formatThreadRow($row);
        }

        return [
            'items' => $threads,
            'pagination' => [
                'limit' => $limit,
                'offset' => $offset,
                'count' => count($threads),
                'has_next' => $hasMore,
                'next_offset' => $hasMore ? $offset + $limit : null,
            ],
        ];
    }

    /**
     * Métadonnées d'un fil SEUL (sans ses messages) — utilisé par le hook push `sav.message`
     * (cf. `RebuildConnector::hookActionObjectCustomerMessageAddAfter()`), qui n'a besoin que du
     * nom de la cliente pour composer la notification, pas de l'historique complet des messages.
     * Même protection IDOR que `getThreadById()` : `null` si absent ou autre boutique.
     *
     * @return array<string, mixed>|null
     */
    public function getThreadSummary(int $idThread): ?array
    {
        $threadRow = $this->fetchThreadRow($idThread);

        return $threadRow === null ? null : $this->formatThreadRow($threadRow);
    }

    /**
     * Fil complet (métadonnées + messages dans l'ordre chronologique). `null` si absent ou
     * appartenant à une autre boutique (protection IDOR — traité comme "introuvable", pas 403,
     * pour ne pas confirmer l'existence d'un fil d'une autre boutique).
     *
     * @return array{thread: array<string, mixed>, messages: array<int, array<string, mixed>>}|null
     */
    public function getThreadById(int $idThread): ?array
    {
        $threadRow = $this->fetchThreadRow($idThread);
        if ($threadRow === null) {
            return null;
        }

        $messagesQuery = new DbQuery();
        $messagesQuery->select(
            'cm.id_customer_message, cm.id_employee, cm.message, cm.private, cm.`read`, cm.date_add, '
            . 'e.firstname AS employee_firstname, e.lastname AS employee_lastname'
        );
        $messagesQuery->from('customer_message', 'cm');
        $messagesQuery->leftJoin('employee', 'e', 'e.id_employee = cm.id_employee AND cm.id_employee != 0');
        $messagesQuery->where('cm.id_customer_thread = ' . (int) $idThread);
        $messagesQuery->orderBy('cm.date_add ASC, cm.id_customer_message ASC');

        $messageRows = Db::getInstance()->executeS($messagesQuery);
        $messageRows = is_array($messageRows) ? $messageRows : [];

        $messages = [];
        foreach ($messageRows as $row) {
            /** @var array<string, mixed> $row */
            $messages[] = $this->formatMessageRow($row);
        }

        return [
            'thread' => $this->formatThreadRow($threadRow),
            'messages' => $messages,
        ];
    }

    /**
     * Ajoute une réponse du marchand ET envoie un e-mail RÉEL à la cliente (mécanisme documenté
     * dans `docs/api.md` — mail template propre au connecteur, `rebuildconnector/mails/`, PAS le
     * template `contact` du cœur PrestaShop dont l'existence/l'emplacement exacts ne sont pas
     * vérifiables sans installation PS8 de référence).
     *
     * `null` si le fil est introuvable ou appartient à une autre boutique.
     *
     * @return array{thread: array<string, mixed>, message: array<string, mixed>, email_sent: bool}|null
     */
    public function reply(int $idThread, string $message, ?int $idEmployee, string $ipAddress, string $userAgent): ?array
    {
        $message = trim($message);
        if ($message === '') {
            throw new \InvalidArgumentException('The message field is required.');
        }
        if (function_exists('mb_strlen') ? mb_strlen($message) > self::REPLY_MAX_LENGTH : strlen($message) > self::REPLY_MAX_LENGTH) {
            throw new \InvalidArgumentException('The message is too long.');
        }

        $threadRow = $this->fetchThreadRow($idThread);
        if ($threadRow === null) {
            return null;
        }

        $employeeId = $idEmployee !== null && $idEmployee > 0 ? $idEmployee : 0;
        $now = date('Y-m-d H:i:s');

        // file_name/ip_address/user_agent : toujours une valeur concrète (jamais null) — cf.
        // piège rappelé par le mandat de tâche, Db::insert() transforme null en chaîne vide, donc
        // autant fixer la chaîne vide explicitement plutôt que de compter sur cette conversion.
        Db::getInstance()->insert('customer_message', [
            'id_employee' => $employeeId,
            'id_customer_thread' => (int) $idThread,
            'message' => $message,
            'file_name' => '',
            'ip_address' => $ipAddress !== '' ? $ipAddress : '0.0.0.0',
            'user_agent' => $userAgent !== '' ? $userAgent : 'PrestaFlow',
            'private' => 0,
            'read' => 1, // déjà "lu" par son propre auteur (le marchand qui vient de l'écrire).
            'date_add' => $now,
        ]);
        $newMessageId = Db::getInstance()->Insert_ID();

        Db::getInstance()->update(
            'customer_thread',
            [
                'status' => self::STATUS_AFTER_REPLY,
                'date_upd' => $now,
            ],
            'id_customer_thread = ' . (int) $idThread
        );

        $emailSent = $this->sendReplyEmail($threadRow, $message);

        $updatedThreadRow = $this->fetchThreadRow($idThread);
        $updatedThreadRow = $updatedThreadRow ?? $threadRow;

        return [
            'thread' => $this->formatThreadRow($updatedThreadRow),
            'message' => $this->formatMessageRow([
                'id_customer_message' => $newMessageId,
                'id_employee' => $employeeId,
                'message' => $message,
                'private' => 0,
                'read' => 1,
                'date_add' => $now,
                'employee_firstname' => null,
                'employee_lastname' => null,
            ]),
            'email_sent' => $emailSent,
        ];
    }

    /**
     * Change le statut d'un fil (aucun message ni e-mail). `null` si introuvable/autre boutique.
     *
     * @return array<string, mixed>|null
     */
    public function changeStatus(int $idThread, string $status): ?array
    {
        if (!in_array($status, self::VALID_STATUSES, true)) {
            throw new \InvalidArgumentException('Unknown thread status.');
        }

        $threadRow = $this->fetchThreadRow($idThread);
        if ($threadRow === null) {
            return null;
        }

        Db::getInstance()->update(
            'customer_thread',
            [
                'status' => $status,
                'date_upd' => date('Y-m-d H:i:s'),
            ],
            'id_customer_thread = ' . (int) $idThread
        );

        $updatedThreadRow = $this->fetchThreadRow($idThread);
        $updatedThreadRow = $updatedThreadRow ?? $threadRow;

        return $this->formatThreadRow($updatedThreadRow);
    }

    private function threadSelectFields(): string
    {
        return 'ct.id_customer_thread, ct.id_customer, ct.id_order, ct.id_lang, ct.status, ct.email, '
            . 'ct.date_add, ct.date_upd, c.firstname, c.lastname, o.reference AS order_reference, '
            . '(SELECT MAX(cm.date_add) FROM `' . _DB_PREFIX_ . 'customer_message` cm '
            . ' WHERE cm.id_customer_thread = ct.id_customer_thread) AS last_message_at, '
            . '(SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'customer_message` cm2 '
            . ' WHERE cm2.id_customer_thread = ct.id_customer_thread AND cm2.id_employee = 0 AND cm2.`read` = 0) AS unread_count';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function fetchThreadRow(int $idThread): ?array
    {
        if ($idThread <= 0) {
            return null;
        }

        $idShop = $this->getCurrentShopId();

        $query = new DbQuery();
        $query->select($this->threadSelectFields());
        $query->from('customer_thread', 'ct');
        $query->leftJoin('customer', 'c', 'c.id_customer = ct.id_customer');
        $query->leftJoin('orders', 'o', 'o.id_order = ct.id_order');
        $query->where('ct.id_customer_thread = ' . $idThread);
        if ($idShop > 0) {
            $query->where('ct.id_shop = ' . $idShop);
        }

        $row = Db::getInstance()->getRow($query);

        return is_array($row) ? $row : null;
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatThreadRow(array $row): array
    {
        $firstname = isset($row['firstname']) ? trim((string) $row['firstname']) : '';
        $lastname = isset($row['lastname']) ? trim((string) $row['lastname']) : '';
        $customerName = trim($firstname . ' ' . $lastname);
        $idCustomer = isset($row['id_customer']) ? (int) $row['id_customer'] : 0;
        $idOrder = isset($row['id_order']) ? (int) $row['id_order'] : 0;
        $lastMessageAt = $row['last_message_at'] ?? null;

        return [
            'id' => (int) $row['id_customer_thread'],
            'status' => (string) $row['status'],
            'unread' => ((int) ($row['unread_count'] ?? 0)) > 0,
            'customer' => [
                'id' => $idCustomer > 0 ? $idCustomer : null,
                'name' => $customerName !== '' ? $customerName : null,
                'email' => (string) ($row['email'] ?? ''),
            ],
            'order' => $idOrder > 0 ? [
                'id' => $idOrder,
                'reference' => isset($row['order_reference'])
                    ? (string) $row['order_reference']
                    : null,
            ] : null,
            'last_message_at' => $lastMessageAt !== null ? (string) $lastMessageAt : (string) $row['date_add'],
            'date_add' => (string) $row['date_add'],
            'date_upd' => (string) $row['date_upd'],
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function formatMessageRow(array $row): array
    {
        $idEmployee = isset($row['id_employee']) ? (int) $row['id_employee'] : 0;
        $employeeFirstname = isset($row['employee_firstname']) ? trim((string) $row['employee_firstname']) : '';
        $employeeLastname = isset($row['employee_lastname']) ? trim((string) $row['employee_lastname']) : '';
        $employeeName = trim($employeeFirstname . ' ' . $employeeLastname);

        return [
            'id' => (int) $row['id_customer_message'],
            'author' => $idEmployee > 0 ? 'employee' : 'customer',
            'employee_name' => $idEmployee > 0 && $employeeName !== '' ? $employeeName : null,
            'message' => (string) $row['message'],
            'private' => (bool) $row['private'],
            'read' => (bool) $row['read'],
            'date_add' => (string) $row['date_add'],
        ];
    }

    /**
     * @param array<string, mixed> $threadRow
     */
    private function sendReplyEmail(array $threadRow, string $message): bool
    {
        $email = isset($threadRow['email']) ? trim((string) $threadRow['email']) : '';
        if (!Validate::isEmail($email)) {
            return false; // Fil sans adresse exploitable : rien à envoyer (ne bloque pas la réponse).
        }

        $idLang = isset($threadRow['id_lang']) ? (int) $threadRow['id_lang'] : (int) Configuration::get('PS_LANG_DEFAULT');
        $idShop = $this->getCurrentShopId();

        $firstname = isset($threadRow['firstname']) ? trim((string) $threadRow['firstname']) : '';
        if ($firstname === '') {
            $firstname = 'Bonjour';
        }

        $orderReference = isset($threadRow['order_reference'])
            ? (string) $threadRow['order_reference']
            : '';
        // Mail::Send fait un simple remplacement de chaîne (pas de moteur de gabarit avec
        // conditions) : la phrase optionnelle est donc déjà entièrement composée ici, pas
        // reconstituée dans le .html/.txt à partir d'un fragment brut.
        $orderReferenceBlock = $orderReference !== '' ? ' à propos de la commande ' . $orderReference : '';

        return (bool) Mail::Send(
            $idLang,
            'sav_reply',
            'Réponse à votre message',
            [
                '{firstname}' => $firstname,
                // Mail::Send applique le MÊME tableau de variables au gabarit .html ET au gabarit
                // .txt (simple remplacement de chaîne, aucune transformation par fichier) : deux
                // clés distinctes, chacune déjà mise en forme pour son gabarit, plutôt qu'une seule
                // valeur échappée HTML qui casserait l'affichage texte brut.
                '{message_html}' => nl2br(htmlspecialchars($message, ENT_QUOTES, 'UTF-8')),
                '{message_text}' => $message,
                '{order_reference_block}' => $orderReferenceBlock,
                '{shop_name}' => (string) Configuration::get('PS_SHOP_NAME'),
            ],
            $email,
            null,
            null,
            null,
            null,
            null,
            _PS_MODULE_DIR_ . 'rebuildconnector/mails/',
            false,
            $idShop > 0 ? $idShop : null
        );
    }

    private function getCurrentShopId(): int
    {
        $context = Context::getContext();

        return $context->shop instanceof Shop ? (int) $context->shop->id : 0;
    }
}
