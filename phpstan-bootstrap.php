<?php

// Minimal PrestaShop stubs for PHPStan analysis.

class Context
{
    /** @var Smarty */
    public $smarty;

    public function __construct()
    {
        $this->smarty = new Smarty();
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
}

class Configuration
{
    /**
     * @param mixed $value
     */
    public static function updateValue(string $key, $value): bool
    {
        return true;
    }
}
