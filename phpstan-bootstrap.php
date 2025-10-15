<?php

// Minimal PrestaShop stubs for PHPStan analysis.

class Context
{
    /** @var Smarty */
    public $smarty;
    /** @var Language */
    public $language;

    public function __construct()
    {
        $this->smarty = new Smarty();
        $this->language = new Language();
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
    /** @var Context */
    public $context;
    /** @var string */
    protected $_path = '';

    public function __construct()
    {
        $this->context = Context::getContext();
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

    protected function l(string $string): string
    {
        return $string;
    }

    public function display(string $file, string $template): string
    {
        return '';
    }
}

class ModuleFrontController
{
    /** @var bool */
    public $ssl = true;
    /** @var bool */
    public $display_header = false;
    /** @var bool */
    public $display_footer = false;

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
}

class Language
{
    /** @var int */
    public $id = 1;
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
    public $reference;
    /** @var float */
    public $total_paid;
    /** @var int */
    public $id_currency;
}

class OrderState
{
    /** @var string|array<int|string, string> */
    public $name;
}
