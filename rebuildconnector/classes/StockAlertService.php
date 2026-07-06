<?php

defined('_PS_VERSION_') || exit;

/**
 * Anti-spam pour les alertes de stock faible (événement push `stock.low`).
 *
 * Le hook `actionUpdateQuantity` se déclenche à CHAQUE écriture de quantité (y compris
 * `ProductsService::updateStock()` depuis l'app, ou plusieurs décréments successifs pendant un
 * même checkout) — sans état persistant, un produit resté sous son seuil bas déclencherait une
 * notification à chaque appel. Cette table mémorise, par couple (produit, déclinaison), qu'une
 * alerte a déjà été envoyée pour le franchissement en cours :
 *   - une entrée est créée après l'envoi de la notification (franchissement descendant) ;
 *   - elle est supprimée dès que le stock repasse au-dessus du seuil (réarmement), permettant une
 *     nouvelle alerte à la prochaine descente.
 */
class StockAlertService
{
    public const TABLE_NAME = 'rebuildconnector_stock_alert';

    /** Franchissement descendant jamais notifié depuis : à notifier puis marquer alerté. */
    public const ACTION_NOTIFY = 'notify';
    /** Stock repassé au-dessus du seuil : à réarmer (efface l'entrée d'alerte existante). */
    public const ACTION_REARM = 'rearm';
    /** Rien à faire (rupture totale hors périmètre, ou déjà alerté pour ce franchissement). */
    public const ACTION_NONE = 'none';

    /**
     * Logique de franchissement : décide de l'action à mener pour ce couple (produit, déclinaison)
     * à partir de la quantité courante et du seuil effectif, sans effet de bord (ne lit l'état
     * persistant que si nécessaire, n'écrit jamais). Isolée du reste du hook pour rester testable
     * unitairement (mock/stub de l'accès BDD via hasAlert()).
     *
     * - qty > seuil               → ACTION_REARM (réarmement, prochaine descente réalertera).
     * - qty <= 0                  → ACTION_NONE (rupture totale, hors périmètre "stock faible").
     * - 0 < qty <= seuil, déjà alerté → ACTION_NONE (anti-spam).
     * - 0 < qty <= seuil, jamais alerté → ACTION_NOTIFY.
     */
    public function decide(int $idProduct, int $idProductAttribute, int $quantity, int $threshold): string
    {
        if ($quantity > $threshold) {
            return self::ACTION_REARM;
        }

        if ($quantity <= 0) {
            return self::ACTION_NONE;
        }

        if ($this->hasAlert($idProduct, $idProductAttribute)) {
            return self::ACTION_NONE;
        }

        return self::ACTION_NOTIFY;
    }

    public static function install(): bool
    {
        $sql = sprintf(
            'CREATE TABLE IF NOT EXISTS `%s%s` (
                `id_product` INT UNSIGNED NOT NULL,
                `id_product_attribute` INT UNSIGNED NOT NULL DEFAULT 0,
                `alerted_at` DATETIME NOT NULL,
                PRIMARY KEY (`id_product`, `id_product_attribute`)
            ) ENGINE=%s DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci',
            _DB_PREFIX_,
            self::TABLE_NAME,
            _MYSQL_ENGINE_
        );

        return Db::getInstance()->execute($sql);
    }

    public static function uninstall(): void
    {
        $sql = sprintf(
            'DROP TABLE IF EXISTS `%s%s`',
            _DB_PREFIX_,
            self::TABLE_NAME
        );

        Db::getInstance()->execute($sql);
    }

    /**
     * Indique si une alerte a déjà été envoyée pour ce couple (produit, déclinaison) depuis le
     * dernier réarmement (stock repassé au-dessus du seuil).
     */
    public function hasAlert(int $idProduct, int $idProductAttribute): bool
    {
        if ($idProduct <= 0) {
            return false;
        }

        $sql = sprintf(
            'SELECT COUNT(*) FROM `%s%s` WHERE `id_product` = %d AND `id_product_attribute` = %d',
            _DB_PREFIX_,
            self::TABLE_NAME,
            $idProduct,
            max(0, $idProductAttribute)
        );

        return (int) Db::getInstance()->getValue($sql, false) > 0;
    }

    /**
     * Marque le couple (produit, déclinaison) comme « déjà alerté ». Idempotent (upsert).
     */
    public function markAlerted(int $idProduct, int $idProductAttribute): void
    {
        if ($idProduct <= 0) {
            return;
        }

        $now = pSQL(date('Y-m-d H:i:s'));
        $table = sprintf('`%s%s`', _DB_PREFIX_, self::TABLE_NAME);

        $sql = sprintf(
            'INSERT INTO %s (`id_product`, `id_product_attribute`, `alerted_at`)
             VALUES (%d, %d, "%s")
             ON DUPLICATE KEY UPDATE `alerted_at` = VALUES(`alerted_at`)',
            $table,
            $idProduct,
            max(0, $idProductAttribute),
            $now
        );

        Db::getInstance()->execute($sql);
    }

    /**
     * Réarme l'alerte pour ce couple (produit, déclinaison) : à appeler quand le stock repasse
     * au-dessus du seuil, pour qu'une nouvelle descente redéclenche une notification.
     */
    public function clearAlert(int $idProduct, int $idProductAttribute): void
    {
        if ($idProduct <= 0) {
            return;
        }

        $sql = sprintf(
            'DELETE FROM `%s%s` WHERE `id_product` = %d AND `id_product_attribute` = %d',
            _DB_PREFIX_,
            self::TABLE_NAME,
            $idProduct,
            max(0, $idProductAttribute)
        );

        Db::getInstance()->execute($sql);
    }
}
