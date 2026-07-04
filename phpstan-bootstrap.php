<?php

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', __DIR__ . '/');
}

if (!defined('_PS_ROOT_DIR_')) {
    define('_PS_ROOT_DIR_', __DIR__ . '/');
}

if (!defined('_PS_CACHE_DIR_')) {
    define('_PS_CACHE_DIR_', __DIR__ . '/var/cache');
}

if (!defined('_DB_PREFIX_')) {
    define('_DB_PREFIX_', 'ps_');
}

if (!defined('_PS_MODE_DEV_')) {
    define('_PS_MODE_DEV_', true);
}

if (!defined('_MYSQL_ENGINE_')) {
    define('_MYSQL_ENGINE_', 'InnoDB');
}

if (!defined('_PS_USE_SQL_SLAVE_')) {
    define('_PS_USE_SQL_SLAVE_', false);
}

if (!defined('__PS_BASE_URI__')) {
    define('__PS_BASE_URI__', '/');
}

function pSQL(string $string, bool $htmlOK = false): string
{
    return $string;
}

class Context
{
    /** @var Smarty */
    public $smarty;
    /** @var Language|null */
    public $language;
    /** @var Shop|null */
    public $shop;
    /** @var Employee|null */
    public $employee;
    /** @var Link|null */
    public $link;

    public function __construct()
    {
        $this->smarty = new Smarty();
        $this->language = null;
        $this->shop = new Shop();
        $this->employee = new Employee();
        $this->link = null;
    }

    public static function getContext(): self
    {
        return new self();
    }
}

class Smarty
{
    /**
     * @param array<string, mixed> $params
     */
    public function assign(array $params): void
    {
    }
}

class Shop
{
    /** @var int */
    public $id = 1;
}

class Link
{
    public function getImageLink(string $rewrite, string $idImage, string $type = 'large_default'): string
    {
        return 'https://example.com/img/' . $idImage . '.jpg';
    }

    /**
     * @param array<string, mixed> $params
     * @param bool|null $ssl
     */
    public function getModuleLink(string $module, string $controller = '', array $params = [], $ssl = null): string
    {
        return 'https://example.com/module/' . $module . '/' . $controller;
    }

    /**
     * @param bool|null $ssl
     */
    public function getBaseLink(?int $idShop = null, $ssl = null): string
    {
        return 'https://example.com/';
    }
}

class Cart
{
    /** @var int */
    public $id;

    public function __construct(int $id = 0)
    {
        $this->id = $id;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(): array
    {
        return [];
    }

    public function getOrderTotal(bool $withTaxes = true): float
    {
        return 0.0;
    }

    public function getNbProducts(): int
    {
        return 0;
    }
}

class Image
{
    /** @var int */
    public $id = 0;
    /** @var int */
    public $id_image = 0;
    /** @var int */
    public $id_product = 0;
    /** @var int */
    public $position = 0;
    /** @var bool|int|null */
    public $cover;
    /** @var string */
    public $image_format = 'jpg';

    public function __construct(?int $idImage = null, ?int $idLang = null)
    {
    }

    public function add(bool $autoDate = true, bool $nullValues = false): bool
    {
        return true;
    }

    public function delete(): bool
    {
        return true;
    }

    /**
     * @return string|false
     */
    public function getPathForCreation()
    {
        return sys_get_temp_dir() . '/ps-image-stub-' . (int) $this->id;
    }

    /**
     * @return string|false
     */
    public function getExistingImgPath()
    {
        return '';
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getCover(int $idProduct)
    {
        return ['id_image' => 0];
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getGlobalCover(int $idProduct)
    {
        return ['id_image' => 0];
    }

    /**
     * @return int|null
     */
    public static function getHighestPosition(int $idProduct)
    {
        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>|false
     */
    public static function getImages(int $idLang, int $idProduct)
    {
        return [];
    }
}

class ImageType
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getImagesTypes(?string $type = null): array
    {
        return [];
    }
}

class ImageManager
{
    /**
     * Bascule de test : permet aux tests PHPUnit de simuler un échec de redimensionnement
     * (rollback de l'Image nouvellement créée dans ProductsService::addProductImage).
     */
    public static bool $resizeSucceeds = true;

    /**
     * @param int|string|null $destinationWidth
     * @param int|string|null $destinationHeight
     */
    public static function resize(
        string $sourceFile,
        string $destinationFile,
        $destinationWidth = null,
        $destinationHeight = null,
        string $fileType = 'jpg',
        bool $forceType = false
    ): bool {
        return self::$resizeSucceeds;
    }

    public static function checkImageMemoryLimit(string $image): bool
    {
        return true;
    }
}

class Employee
{
    /** @var int */
    public $id = 1;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getEmployees(bool $onlyActive = true): array
    {
        return [];
    }
}

class Module
{
    public static function isEnabled(string $moduleName): bool
    {
        return false;
    }

    public static function isInstalled(string $moduleName): bool
    {
        return false;
    }

    /** @var string */
    public $name;
    /** @var string */
    public $tab;
    /** @var string */
    public $version;
    /** @var string */
    public $author;
    /** @var int */
    public $need_instance = 0;
    /** @var bool */
    public $bootstrap = false;
    /** @var array<int, string> */
    public $controllers = [];
    /** @var string */
    public $displayName;
    /** @var string */
    public $description;
    /** @var array<string, string> */
    public $ps_versions_compliancy = [];
    /** @var Context|null */
    public $context = null;
    /** @var string */
    protected $_path = '';

    public function __construct()
    {
        // Stub: leave context nullable.
    }

    public function install(): bool
    {
        return true;
    }

    public function uninstall(): bool
    {
        return true;
    }

    public function registerHook(string $hookName): bool
    {
        return true;
    }

    public function displayConfirmation(string $message): string
    {
        return $message;
    }

    public function displayError(string $message): string
    {
        return $message;
    }

    public function displayWarning(string $message): string
    {
        return $message;
    }

    protected function l(string $string): string
    {
        return $string;
    }

    public function display(string $file, string $template): string
    {
        return '';
    }

    /**
     * @return static|false
     */
    public static function getInstanceByName(string $moduleName)
    {
        return false;
    }

    /**
     * @return bool|array<int, string>
     */
    public function runUpgradeModule()
    {
        return true;
    }
}

class ModuleFrontController extends Module
{
    public function init(): void
    {
    }

    public function initContent(): void
    {
    }

    public function ajaxRender(string $output): void
    {
    }
}

class Tools
{
    public static function isSubmit(string $key): bool
    {
        return false;
    }

    /**
     * @return array<string, mixed>
     */
    public static function getAllValues(): array
    {
        return [];
    }

    /**
     * @param mixed $price
     * @param Currency|null $currency
     */
    public static function displayPrice($price, $currency = null): string
    {
        return (string) $price;
    }

    /**
     * Retour typé en union (au lieu d'un `string` natif strict) pour rester fidèle aux
     * vérifications défensives déjà présentes dans le code métier (is_string(...)) : le cœur
     * PrestaShop ne garantit pas un type strict sur cette méthode selon les versions.
     *
     * @return string|false
     */
    public static function getShopDomainSsl(bool $http = false)
    {
        return 'https://example.com';
    }

    public static function getRemoteAddr(): string
    {
        return '127.0.0.1';
    }

    /**
     * @param mixed $default
     * @return mixed
     */
    public static function getValue(string $key, $default = null)
    {
        // Fidélité aux tests : PrestaShop lit la valeur depuis la requête ($_POST prioritaire, puis
        // $_GET). On reproduit ce comportement pour que les tests puissent piloter les paramètres via
        // les superglobales. Absence de valeur → défaut (comme le vrai Tools::getValue).
        if (isset($_POST[$key])) {
            return $_POST[$key];
        }
        if (isset($_GET[$key])) {
            return $_GET[$key];
        }

        return $default;
    }

    public static function file_get_contents(string $filename)
    {
        return '';
    }

    public static function passwdGen(int $length = 8): string
    {
        return str_repeat('a', max(1, $length));
    }

    public static function strlen(string $string): int
    {
        return strlen($string);
    }

    public static function substr(string $string, int $start, ?int $length = null): string
    {
        return $length === null ? substr($string, $start) : substr($string, $start, $length);
    }

    public static function strtolower(string $string): string
    {
        return strtolower($string);
    }

    public static function strtoupper(string $string): string
    {
        return strtoupper($string);
    }
}

class Configuration
{
    public static function get(string $key)
    {
        return null;
    }

    /**
     * @param mixed $value
     */
    public static function updateValue(string $key, $value): bool
    {
        return true;
    }

    public static function deleteByName(string $key): bool
    {
        return true;
    }
}

class Validate
{
    /**
     * @param mixed $object
     */
    public static function isLoadedObject($object): bool
    {
        return $object !== null;
    }

    /**
     * @param mixed $url
     */
    public static function isUrl($url): bool
    {
        if (!is_string($url) || $url === '') {
            return false;
        }

        return filter_var($url, FILTER_VALIDATE_URL) !== false;
    }

    /**
     * Stub simplifié du cœur PrestaShop : rejette les caractères réservés `<>;=#{}`.
     */
    public static function isCatalogName(string $name): bool
    {
        return preg_match('/^[^<>;=#{}]*$/u', $name) === 1;
    }

    /**
     * Stub simplifié du cœur PrestaShop : rejette les balises dangereuses (script/iframe/onXxx).
     */
    public static function isCleanHtml(string $html, bool $allowIframe = false): bool
    {
        if (preg_match('/<\s*script/i', $html) === 1) {
            return false;
        }

        if (!$allowIframe && preg_match('/<\s*iframe/i', $html) === 1) {
            return false;
        }

        return preg_match('/\son[a-z]+\s*=/i', $html) !== 1;
    }

    /**
     * Stub simplifié du cœur PrestaShop : rejette les caractères réservés `<>;={}`.
     */
    public static function isReference(string $reference): bool
    {
        return preg_match('/^[^<>;={}]*$/u', $reference) === 1;
    }
}

class Db
{
    /**
     * Bascule de test : valeur retournée par getValue() (par défaut 0, comportement historique du stub).
     * Permet de simuler par ex. une vérification d'appartenance en base (ex. combination_id d'un produit)
     * sans base de données réelle.
     *
     * @var mixed
     */
    public static $testGetValueResult = 0;

    /**
     * Bascule de test : lignes retournées par executeS() (par défaut [], comportement historique du
     * stub). Permet de simuler par ex. les déclinaisons d'un produit (product_attribute) sans base réelle.
     *
     * @var array<int, array<string, mixed>>
     */
    public static array $testExecuteSResult = [];

    /**
     * Bascule de test : journal des requêtes passées à execute() (INSERT/DELETE...). Permet de
     * vérifier, sans base réelle, qu'une purge génère bien le bon DELETE avec le bon seuil de date.
     *
     * @var array<int, string>
     */
    public static array $executedSql = [];

    /**
     * Bascule de test : journal des requêtes SELECT passées à getValue()/executeS() SOUS FORME DE
     * CHAÎNE (les appels via DbQuery ne sont pas capturés ici, le stub DbQuery ne conservant pas
     * son SQL). Permet de vérifier, sans base réelle, qu'une requête bâtie par concaténation directe
     * (ex. DashboardService, qui n'utilise pas toujours DbQuery) contient bien la clause id_shop
     * attendue (protection IDOR multiboutique, m1).
     *
     * @var array<int, string>
     */
    public static array $testLoggedSelectQueries = [];

    public static function getInstance(bool $useSlave = false): self
    {
        return new self();
    }

    /**
     * Retour élargi en union `|false` (au lieu d'un `array` natif strict) pour rester fidèle
     * au cœur PrestaShop (executeS() peut échouer et retourner false) et aux annotations
     * défensives déjà présentes dans le code métier.
     *
     * @param mixed $query
     * @return array<int, array<string, mixed>>|false
     */
    public function executeS($query)
    {
        if (is_string($query)) {
            self::$testLoggedSelectQueries[] = $query;
        }

        return self::$testExecuteSResult;
    }

    /**
     * @param mixed $query
     * @return array<string, mixed>|false
     */
    public function getRow($query, bool $useCache = true)
    {
        return false;
    }

    public function Insert_ID(): int
    {
        return 0;
    }

    /**
     * @param string $table
     * @param array<string, mixed> $data
     */
    public function update(string $table, array $data, string $where = ''): bool
    {
        return true;
    }

    /**
     * @param string $table
     * @param array<string, mixed> $data
     */
    public function insert(string $table, array $data): bool
    {
        return true;
    }

    public function delete(string $table, string $where = ''): bool
    {
        return true;
    }

    public function execute(string $sql): bool
    {
        self::$executedSql[] = $sql;

        return true;
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function getValue($query, bool $useCache = true)
    {
        if (is_string($query)) {
            self::$testLoggedSelectQueries[] = $query;
        }

        return self::$testGetValueResult;
    }
}

class DbQuery
{
    /**
     * Bascule de test : journal des champs passés à select() (stub sans état réel sinon).
     * Permet de verrouiller le SQL construit via DbQuery (ex. requêtes du graphique dashboard
     * qui n'utilisent pas la concaténation directe, donc absentes de Db::$testLoggedSelectQueries).
     *
     * @var array<int, string>
     */
    public static array $testSelectLog = [];

    /**
     * @param string $fields
     */
    public function select($fields): self
    {
        self::$testSelectLog[] = $fields;

        return $this;
    }

    public function from(string $table, string $alias = ''): self
    {
        return $this;
    }

    public function innerJoin(string $table, string $alias, string $on): self
    {
        return $this;
    }

    public function leftJoin(string $table, string $alias, string $on): self
    {
        return $this;
    }

    public function where(string $condition): self
    {
        return $this;
    }

    public function groupBy(string $fields): self
    {
        return $this;
    }

    public function orderBy(string $fields): self
    {
        return $this;
    }

    public function limit(int $limit, int $offset = 0): self
    {
        return $this;
    }
}

class Language
{
    /** @var int */
    public $id = 1;
    /** @var string|null */
    public $iso_code = null;

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getLanguages(bool $active = true, $idShop = false, bool $idsOnly = false): array
    {
        return [
            ['id_lang' => 1, 'iso_code' => 'fr'],
        ];
    }
}

class Currency
{
    /** @var string */
    public $iso_code = 'EUR';

    public function __construct(int $idCurrency)
    {
    }
}

class Order
{
    /** @var int */
    public $id;
    /** @var string */
    public $reference = '';
    /** @var int */
    public $current_state = 0;
    /** @var int */
    public $id_currency = 0;
    /** @var int */
    public $id_customer = 0;
    /** @var float */
    public $total_paid_tax_incl = 0.0;
    /** @var float */
    public $total_paid_tax_excl = 0.0;
    /** @var string */
    public $shipping_number = '';
    /** @var int */
    public $id_carrier = 0;
    /** @var string */
    public $date_add = '';
    /** @var string */
    public $date_upd = '';
    /** @var int */
    public $id_shop = 1;
    /** @var int */
    public $id_address_delivery = 0;
    /** @var float */
    public $total_shipping_tax_excl = 0.0;
    /** @var int */
    public $invoice_number = 0;

    /**
     * Crochets de test (unitaires uniquement) : permettent de simuler une commande d'une autre
     * boutique (id_shop) et une collection de factures non vide, sans BDD réelle.
     *
     * @var int|null
     */
    public static $testIdShop = null;
    /** @var array<int, OrderInvoice> */
    public static $testInvoices = [];

    public function __construct(int $id)
    {
        $this->id = $id;
        if (self::$testIdShop !== null) {
            $this->id_shop = self::$testIdShop;
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getProducts(): array
    {
        return [];
    }

    public function update(): bool
    {
        return true;
    }

    public function getIdOrderCarrier(): int
    {
        return 0;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function getHistory(int $idLang, ?int $idOrderState = null, bool $withHiddenState = false): array
    {
        return [];
    }

    /**
     * @return array<int, OrderInvoice>
     */
    public function getInvoicesCollection(): array
    {
        return self::$testInvoices;
    }
}

class OrderState
{
    /** @var string|array<int|string, string> */
    public $name = '';

    public function __construct(int $id_order_state, ?int $id_lang = null)
    {
    }
}

class OrderHistory
{
    /** @var int */
    public $id_order = 0;
    /** @var int */
    public $id_employee = 0;

    public function changeIdOrderState(int $stateId, int $orderId): void
    {
    }

    public function addWithemail(bool $sendEmail = false): bool
    {
        return true;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public static function getHistory(int $langId, int $orderId): array
    {
        return [];
    }
}

class OrderCarrier
{
    /** @var string */
    public $tracking_number = '';
    /** @var int */
    public $id_carrier = 0;
    /** @var int */
    public $id_order = 0;

    public function __construct(int $id_order_carrier = 0)
    {
    }

    public static function getIdByOrderId(int $orderId): int
    {
        return 0;
    }

    public function update(): bool
    {
        return true;
    }

    public function add(): bool
    {
        return true;
    }
}

class Carrier
{
    /** @var string */
    public $name = '';
    /** @var int */
    public $id_reference = 0;

    public function __construct(int $id_carrier, int $id_lang = 0)
    {
    }
}

class Address
{
    /** @var int */
    public $id_country = 0;
    /** @var int */
    public $id_state = 0;
    /**
     * Champs réellement optionnels dans le cœur PrestaShop (nullable en base) : company,
     * address2, phone_mobile. Typés nullable ici pour rester fidèles aux `?? ''` défensifs
     * déjà présents dans le code métier.
     *
     * @var string|null
     */
    public $company = '';
    /** @var string */
    public $lastname = '';
    /** @var string */
    public $firstname = '';
    /** @var string */
    public $address1 = '';
    /** @var string|null */
    public $address2 = '';
    /** @var string */
    public $city = '';
    /** @var string */
    public $postcode = '';
    /** @var string */
    public $phone = '';
    /** @var string|null */
    public $phone_mobile = '';

    public function __construct(int $id = 0)
    {
    }
}

class Country
{
    public static function getIsoById(int $idCountry): string
    {
        return 'FR';
    }
}

class State
{
    /** @var string */
    public $iso_code = '';

    public function __construct(int $id = 0)
    {
    }
}

class Customer
{
    /** @var string */
    public $firstname = '';
    /** @var string */
    public $lastname = '';
    /** @var string */
    public $email = '';
    /** @var string */
    public $date_add = '';

    public function __construct(int $id_customer)
    {
    }
}

class Product
{
    /** @var float */
    public $price = 0.0;
    /** @var bool */
    public $active = true;
    /** @var string */
    public $ean13 = '';
    /** @var string|array<int, string> */
    public $link_rewrite = '';
    /** @var string|array<int, string> */
    public $name = '';
    /** @var string|array<int, string> */
    public $description = '';
    /** @var string|array<int, string> */
    public $description_short = '';
    /** @var string */
    public $reference = '';

    public function __construct(int $id_product, bool $full = false, ?int $id_lang = null, ?int $id_shop = null)
    {
    }

    public static function getPriceStatic(int $idProduct, bool $withTax): float
    {
        return 0.0;
    }

    /**
     * @return array<string, mixed>|false
     */
    public static function getCover(int $idProduct)
    {
        return ['id_image' => 0];
    }

    public function update(): bool
    {
        return true;
    }
}

class Combination
{
    /**
     * Bascule de test : enregistre les appels reçus par update() (id_product_attribute, ean13) pour
     * permettre aux tests d'asserter qu'une association EAN13 cible bien la combinaison, pas le produit.
     *
     * @var array<int, array{id_product_attribute: int, ean13: string}>
     */
    public static array $updateCalls = [];

    /** @var int */
    public $id = 0;
    /** @var int */
    public $id_product = 0;
    /** @var string */
    public $ean13 = '';
    /** @var string */
    public $reference = '';

    public function __construct(int $id_product_attribute = 0)
    {
        $this->id = $id_product_attribute;
    }

    public function update(): bool
    {
        self::$updateCalls[] = ['id_product_attribute' => $this->id, 'ean13' => $this->ean13];

        return true;
    }
}

class StockAvailable
{
    /**
     * Bascule de test : enregistre les appels reçus par setQuantity() (id_product, id_product_attribute,
     * quantity) pour permettre aux tests d'asserter le ciblage produit vs combinaison sans base réelle.
     *
     * @var array<int, array{0: int, 1: int, 2: int}>
     */
    public static array $setQuantityCalls = [];

    public static function setQuantity(int $idProduct, int $idProductAttribute, int $quantity): void
    {
        self::$setQuantityCalls[] = [$idProduct, $idProductAttribute, $quantity];
    }

    public static function getQuantityAvailableByProduct(int $idProduct, ?int $idProductAttribute = null, ?int $idShop = null): int
    {
        return 0;
    }
}

class OrderInvoice
{
    /** @var int */
    public $id = 0;
    /** @var int */
    public $id_order = 0;
    /** @var int */
    public $number = 0;

    /**
     * @return array<int, static>
     */
    public static function getByOrderId(int $idOrder): array
    {
        return [];
    }
}

class PDF
{
    public const TEMPLATE_INVOICE = 'Invoice';

    /** @var string|false Crochet de test : contenu simulé renvoyé par render(). */
    public static $testRenderResult = '';

    /**
     * @param array<int, mixed> $objects
     * @param string $template
     * @param mixed $smarty
     */
    public function __construct(array $objects, string $template, $smarty)
    {
    }

    /**
     * Retour typé en union (au lieu d'un `string` natif strict) pour rester fidèle aux
     * vérifications défensives déjà présentes dans le code métier (=== false, is_string(...)).
     *
     * @return string|false
     */
    public function render(bool $display = true)
    {
        return self::$testRenderResult;
    }
}

// =============================================================================
// Stubs SDK externe : classes lib du module Colissimo (tiers, chargées à l'exécution via
// require_once conditionnel — cf. ColissimoLabelService::requireColissimoLibClasses()).
// Ces classes ne sont PAS fournies par ce module ni par PrestaShop core : elles ne sont
// déclarées ici que pour la satisfaction de PHPStan (jamais utilisées au runtime réel).
// =============================================================================

interface ColissimoReturnedResponseInterface
{
}

abstract class AbstractColissimoResponse
{
}

class ColissimoResponseParser
{
}

class ColissimoGenerateLabelResponse extends AbstractColissimoResponse
{
    /** @var string */
    public $parcelNumber = '';
    /** @var array<int, array<string, mixed>> */
    public $messages = [];
    /** @var string */
    public $label = '';
}

abstract class AbstractColissimoRequest
{
}

class ColissimoGenerateLabelRequest extends AbstractColissimoRequest
{
    /**
     * @param array<string, mixed> $credentials
     */
    public function __construct(array $credentials)
    {
    }

    /**
     * @param array<string, mixed> $output
     */
    public function setOutput(array $output): void
    {
    }

    /**
     * @param array<string, mixed> $services
     */
    public function setShipmentServices(array $services): void
    {
    }

    /**
     * @param array<string, mixed> $options
     */
    public function setShipmentOptions(array $options): void
    {
    }

    /**
     * @param array<string, mixed> $address
     */
    public function setSenderAddress(array $address): void
    {
    }

    /**
     * @param array<string, mixed> $address
     */
    public function setAddresseeAddress(array $address): void
    {
    }

    public function buildRequest(): void
    {
    }
}

class ColissimoClient
{
    public function __construct(int $mode = 1)
    {
    }

    public function setRequest(AbstractColissimoRequest $request): void
    {
    }

    public function request(): ColissimoGenerateLabelResponse
    {
        return new ColissimoGenerateLabelResponse();
    }
}
