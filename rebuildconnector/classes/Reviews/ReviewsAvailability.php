<?php

defined('_PS_VERSION_') || exit;

/**
 * Point d'entrée UNIQUE pour répondre à la question « le module rbreviews est-il utilisable
 * sur cette boutique, maintenant ? ».
 *
 * Évalué À CHAUD (aucun cache, ni long ni court) : `Module::isInstalled()`/`isEnabled()` lisent
 * `ps_module`/`ps_module_shop` en base à chaque appel. Une boutique qui désinstalle rbreviews
 * doit être vue au prochain appel, pas seulement après un TTL de cache.
 *
 * Deux consommateurs partagent CETTE méthode plutôt que de réévaluer chacun sa propre condition :
 *  - `CapabilitiesService` (le booléen `reviews` exposé à l'app) ;
 *  - `ReviewsBridgeFactory` (décide s'il instancie le pont réel ou un pont neutre).
 * Sans ce partage, les deux pourraient diverger silencieusement (ex. capacité annoncée `true`
 * mais pont réel indisponible), exactement le piège signalé par l'étude préalable.
 */
class ReviewsAvailability
{
    public const MODULE_NAME = 'rbreviews';

    public static function isAvailable(): bool
    {
        return Module::isInstalled(self::MODULE_NAME) && Module::isEnabled(self::MODULE_NAME);
    }
}
