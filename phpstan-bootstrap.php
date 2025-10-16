<?php

if (!defined('_PS_MODULE_DIR_')) {
    define('_PS_MODULE_DIR_', __DIR__ . '/');
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

    public function __construct()
    {
        $this->smarty = new Smarty();
        $this->language = null;
        $this->shop = new Shop();
        $this->employee = new Employee();
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

class Employee
{
    /** @var int */
    public $id = 1;
}

class Module
{
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

    protected function l(string $string): string
    {
        return $string;
    }

    public function display(string $file, string $template): string
    {
        return '';
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

    public static function getShopDomainSsl(bool $http = false): string
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
}

class Db
{
    public static function getInstance(): self
    {
        return new self();
    }

    /**
     * @param mixed $query
     * @return array<int, array<string, mixed>>
     */
    public function executeS($query): array
    {
        return [];
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
        return true;
    }

    /**
     * @param mixed $query
     * @return mixed
     */
    public function getValue($query)
    {
        return 0;
    }
}

class DbQuery
{
    /**
     * @param string $fields
     */
    public function select($fields): self
    {
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

    public function __construct(int $id)
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

    public function update(): bool
    {
        return true;
    }
}

class OrderState
{
    /** @var string|array<int|string, string> */
    public $name = '';

    public function __construct(int $id_order_state, ?int $id_lang = null)
    {
    }

    public static function getIdByName(string $name, int $id_lang): int
    {
        return 0;
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
    public static function getCarrierNameFromShopName(int $idCarrier): string
    {
        return '';
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

    public function __construct(int $id_product)
    {
    }

    public static function getPriceStatic(int $idProduct, bool $withTax): float
    {
        return 0.0;
    }

    public function update(): bool
    {
        return true;
    }
}

class StockAvailable
{
    public static function setQuantity(int $idProduct, int $idProductAttribute, int $quantity): void
    {
    }
}
