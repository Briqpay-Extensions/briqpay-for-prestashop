<?php
/**
 * Copyright since 2020 Briqpay AB
 *
 * @author    Briqpay AB <hello@briqpay.com>
 * @copyright Since 2020 Briqpay AB
 * @license   https://opensource.org/licenses/AFL-3.0 Academic Free License version 3.0
 *
 * Minimal stand-ins for the PrestaShop classes the module touches.
 */

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses

/**
 * In-memory replacement for PrestaShop's Configuration table.
 */
class Configuration
{
    /** @var array<string, mixed> */
    public static $store = [];

    public static function reset(): void
    {
        self::$store = [];
    }

    /**
     * @return mixed
     */
    public static function get($key, $idLang = null, $idShopGroup = null, $idShop = null, $default = false)
    {
        return array_key_exists($key, self::$store) ? self::$store[$key] : false;
    }

    public static function hasKey($key, $idLang = null, $idShopGroup = null, $idShop = null): bool
    {
        return array_key_exists($key, self::$store);
    }

    public static function updateValue($key, $value, $html = false, $idShopGroup = null, $idShop = null): bool
    {
        self::$store[$key] = $value;

        return true;
    }

    public static function deleteByName($key): bool
    {
        unset(self::$store[$key]);

        return true;
    }
}

/**
 * Records the statements the module would run, and replays canned rows.
 */
class Db
{
    /** @var self|null */
    private static $instance;

    /** @var array<int, array{method:string, args:array}> */
    public $calls = [];

    /** @var array<int, array> Rows returned, in order, by getRow(). */
    public $rows = [];

    public static function getInstance($master = true): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = new self();
    }

    /**
     * @return array|false
     */
    public function getRow($sql, $useCache = true)
    {
        $this->calls[] = ['method' => 'getRow', 'args' => [$sql]];

        return array_shift($this->rows) ?: false;
    }

    /**
     * PrestaShop returns array|false|null here, so the stub must not narrow it
     * -- the module's own is_array() guards are load-bearing.
     *
     * @return array|false|null
     */
    public function executeS($sql, $array = true, $useCache = true)
    {
        $this->calls[] = ['method' => 'executeS', 'args' => [$sql]];

        return [];
    }

    public function execute($sql, $useCache = true): bool
    {
        $this->calls[] = ['method' => 'execute', 'args' => [$sql]];

        return true;
    }

    public function insert($table, $data, $nullValues = false, $useCache = true, $type = 1, $addPrefix = true): bool
    {
        $this->calls[] = ['method' => 'insert', 'args' => [$table, $data]];

        return true;
    }

    public function update($table, $data, $where = '', $limit = 0, $nullValues = false, $useCache = true): bool
    {
        $this->calls[] = ['method' => 'update', 'args' => [$table, $data, $where]];

        return true;
    }

    public function delete($table, $where = '', $limit = 0, $useCache = true, $addPrefix = true): bool
    {
        $this->calls[] = ['method' => 'delete', 'args' => [$table, $where]];

        return true;
    }

    public function getMsgError(): string
    {
        return '';
    }
}

class Cart
{
    public const BOTH = 3;
    public const ONLY_PRODUCTS = 1;
    public const ONLY_SHIPPING = 5;
    public const ONLY_WRAPPING = 6;

    public $id = 1;
    public $id_customer = 1;
    public $id_address_invoice = 1;
    public $id_address_delivery = 1;
    public $id_currency = 1;
    public $id_carrier = 1;

    /** @var array<int, array> */
    public $products = [];

    /** @var array<int, array> */
    public $cartRules = [];

    /** @var array<int, float> Totals keyed by "<withTax>:<type>". */
    public $totals = [];

    public function __construct($id = null)
    {
        if ($id !== null) {
            $this->id = (int) $id;
        }
    }

    public function getProducts($refresh = false): array
    {
        return $this->products;
    }

    public function getCartRules($filter = 0): array
    {
        return $this->cartRules;
    }

    /**
     * @return float
     */
    public function getOrderTotal($withTaxes = true, $type = self::BOTH, $products = null, $idCarrier = null)
    {
        $key = ($withTaxes ? '1' : '0') . ':' . $type;

        return isset($this->totals[$key]) ? (float) $this->totals[$key] : 0.0;
    }

    public function orderExists(): bool
    {
        return false;
    }

    public function update(): bool
    {
        return true;
    }
}

class Order
{
    public $id = 1;
    public $id_cart = 1;
    public $id_currency = 1;
    public $id_carrier = 1;
    public $id_customer = 1;
    public $id_lang = 1;
    public $id_address_invoice = 1;
    public $id_address_delivery = 1;
    public $reference = 'ABCDEFGHI';
    public $module = 'briqpay_payment_module';
    public $current_state = 2;
    public $total_paid_tax_incl = 0.0;
    public $total_paid_tax_excl = 0.0;
    public $total_shipping_tax_incl = 0.0;
    public $total_shipping_tax_excl = 0.0;
    public $total_wrapping_tax_incl = 0.0;
    public $total_wrapping_tax_excl = 0.0;
    public $total_discounts_tax_incl = 0.0;
    public $total_discounts_tax_excl = 0.0;

    /** @var array<int, array> */
    public $productsDetail = [];

    public function __construct($id = null)
    {
        if ($id !== null) {
            $this->id = (int) $id;
        }
    }

    public function getProductsDetail(): array
    {
        return $this->productsDetail;
    }

    public function getCurrentState(): int
    {
        return (int) $this->current_state;
    }

    public static function getOrderByCartId($idCart)
    {
        return false;
    }
}

class Address
{
    public $id = 1;
    public $id_country = 1;
    public $id_state = 0;
    public $firstname = '';
    public $lastname = '';
    public $address1 = '';
    public $address2 = '';
    public $postcode = '';
    public $city = '';
    public $phone = '';
    public $phone_mobile = '';
    public $company = '';
    public $vat_number = '';
    public $other = '';
    public $deleted = false;

    /** @var array<int, array> Registry keyed by id, populated by tests. */
    public static $registry = [];

    public function __construct($id = null)
    {
        if ($id === null) {
            return;
        }

        $this->id = (int) $id;

        foreach (self::$registry[$this->id] ?? [] as $field => $value) {
            $this->$field = $value;
        }
    }

    public static function reset(): void
    {
        self::$registry = [];
    }
}

class Customer
{
    public $id = 1;
    public $email = '';
    public $firstname = '';
    public $lastname = '';
    public $secure_key = 'securekey';
    public $company = '';
    public $siret = '';

    /** @var array<int, array> */
    public static $registry = [];

    public function __construct($id = null)
    {
        if ($id === null) {
            return;
        }

        $this->id = (int) $id;

        foreach (self::$registry[$this->id] ?? [] as $field => $value) {
            $this->$field = $value;
        }
    }

    public static function reset(): void
    {
        self::$registry = [];
    }
}

class Currency
{
    public $id = 1;
    public $iso_code = 'SEK';

    /** @var array<int, string> */
    public static $registry = [];

    public function __construct($id = null)
    {
        if ($id === null) {
            return;
        }

        $this->id = (int) $id;
        $this->iso_code = self::$registry[$this->id] ?? 'SEK';
    }
}

class Carrier
{
    public $id = 1;
    public $id_reference = 1;
    public $name = 'Standard delivery';
    public $delay = ['2-4 days', '2-4 days'];

    /**
     * The rate getTaxesRate() returns. Tests set this directly to prove the
     * shipping line reads it rather than deriving a rate from the (rounded)
     * incVat/exVat totals -- the two agree in most fixtures, so a test that
     * wants to tell them apart has to deliberately mismatch this from the
     * fixture's own totals.
     *
     * @var float
     */
    public static $taxRate = 25.0;

    public function __construct($id = null)
    {
        if ($id !== null) {
            $this->id = (int) $id;
        }
    }

    /**
     * @param Address|null $address
     *
     * @return float
     */
    public function getTaxesRate($address = null)
    {
        return self::$taxRate;
    }
}

/**
 * Enough of PrestaShop's tax manager chain (TaxManagerFactory::getManager()
 * -> TaxCalculator::getTotalRate()) for the wrapping fee's direct rate lookup
 * to run in tests.
 */
class TaxManagerFactory
{
    /** @var float */
    public static $wrappingTaxRate = 25.0;

    /**
     * @param Address $address
     * @param int     $idTaxRulesGroup
     *
     * @return TaxCalculator
     */
    public static function getManager($address, $idTaxRulesGroup)
    {
        return new TaxCalculator(self::$wrappingTaxRate);
    }
}

class TaxCalculator
{
    /** @var float */
    private $rate;

    public function __construct($rate)
    {
        $this->rate = $rate;
    }

    public function getTaxCalculator()
    {
        return $this;
    }

    /**
     * @return float
     */
    public function getTotalRate()
    {
        return $this->rate;
    }
}

class Country
{
    /** @var array<int, string> */
    public static $isoCodes = [1 => 'SE'];

    public $id = 1;
    public $name = ['Sweden'];
    public $iso_code = 'SE';

    public function __construct($id = null)
    {
        if ($id !== null) {
            $this->id = (int) $id;
            $this->iso_code = self::$isoCodes[$this->id] ?? '';
        }
    }

    public static function getIsoById($idCountry)
    {
        return self::$isoCodes[(int) $idCountry] ?? '';
    }
}

class State
{
    /** @var array<int, string> */
    public static $names = [];

    public static function getNameById($idState)
    {
        return self::$names[(int) $idState] ?? '';
    }
}

class Validate
{
    public static function isLoadedObject($object): bool
    {
        return is_object($object) && isset($object->id) && (int) $object->id > 0;
    }

    public static function isUrl($url): bool
    {
        return (bool) filter_var($url, FILTER_VALIDATE_URL);
    }
}

class Hook
{
    /** @var array<int, array{name:string, params:array}> */
    public static $executed = [];

    public static function exec($hookName, $hookArgs = [], $idModule = null)
    {
        self::$executed[] = ['name' => $hookName, 'params' => $hookArgs];

        return '';
    }

    public static function reset(): void
    {
        self::$executed = [];
    }
}

class PrestaShopLogger
{
    /** @var array<int, array{message:string, severity:int}> */
    public static $logs = [];

    public static function addLog($message, $severity = 1, $errorCode = null, $objectType = null, $objectId = null, $allowDuplicate = false, $idEmployee = null): bool
    {
        self::$logs[] = ['message' => $message, 'severity' => $severity];

        return true;
    }

    public static function reset(): void
    {
        self::$logs = [];
    }
}

class Cache
{
    public static function clean($key): bool
    {
        return true;
    }
}

class Tools
{
    public static function substr($str, $start, $length = false)
    {
        return $length === false ? substr($str, $start) : substr($str, $start, $length);
    }

    public static function displayPrice($price, $currency = null, $noUtf8 = false, $context = null): string
    {
        return number_format((float) $price, 2, '.', ' ');
    }
}

class Link
{
    public $baseLink = 'https://shop.example.com/';

    public function getBaseLink($idShop = null, $ssl = null, $relativeProtocol = false): string
    {
        return $this->baseLink;
    }

    public function getModuleLink($module, $controller = 'default', array $params = [], $ssl = null, $idLang = null, $idShop = null, $relativeProtocol = false): string
    {
        $url = $this->baseLink . 'module/' . $module . '/' . $controller;

        return $params ? $url . '?' . http_build_query($params) : $url;
    }

    public function getCMSLink($cms, $alias = null, $ssl = null, $idLang = null): string
    {
        $id = is_object($cms) ? $cms->id : (int) $cms;

        return $this->baseLink . 'content/' . $id . '-terms';
    }

    public function getPageLink($controller, $ssl = null, $idLang = null, $request = null): string
    {
        $url = $this->baseLink . $controller;

        return is_array($request) && $request ? $url . '?' . http_build_query($request) : $url;
    }
}

class Language
{
    public $id = 1;
    public $iso_code = 'en';
    public $locale = 'en-GB';
    public $language_code = 'en-us';

    public function __construct($id = null)
    {
        if ($id !== null) {
            $this->id = (int) $id;
        }
    }

    public static function getLanguages($active = true, $idShop = false, $idsOnly = false): array
    {
        return [['id_lang' => 1, 'iso_code' => 'en']];
    }
}

class Context
{
    /** @var self|null */
    private static $instance;

    public $cart;
    public $link;
    public $language;
    public $currency;
    public $country;
    public $customer;
    public $controller;
    public $smarty;
    public $cookie;

    public function __construct()
    {
        $this->link = new Link();
        $this->language = new Language();
        $this->currency = new Currency(1);
        $this->country = new Country(1);
        $this->country->iso_code = 'SE';
    }

    public static function getContext(): self
    {
        if (self::$instance === null) {
            self::$instance = new self();
        }

        return self::$instance;
    }

    public static function reset(): void
    {
        self::$instance = null;
    }
}

class OrderState
{
    public $id = 0;
    public $name = [];
    public $module_name = '';
    public $color = '';
    public $paid = false;
    public $logable = false;
    public $shipped = false;
    public $delivery = false;
    public $invoice = false;
    public $hidden = false;
    public $deleted = false;
    public $unremovable = false;
    public $send_email = false;

    /** @var array<int, bool> Ids treated as existing. */
    public static $existing = [];

    public function __construct($id = null)
    {
        if ($id !== null && isset(self::$existing[(int) $id])) {
            $this->id = (int) $id;
        }
    }

    public function add($autoDate = true, $nullValues = false): bool
    {
        $this->id = 99;
        self::$existing[99] = true;

        return true;
    }
}

class OrderHistory
{
    public $id_order = 0;

    public function changeIdOrderState($newOrderState, $id_order, $useExistingPayment = false): void
    {
    }

    public function addWithemail($autodate = true, $templateVars = false): bool
    {
        return true;
    }
}

class Message
{
    public $id_order = 0;
    public $message = '';
    public $private = true;

    public function add($autoDate = true, $nullValues = false): bool
    {
        return true;
    }
}

class Module
{
    public $name = 'briqpay_payment_module';
    public $version = '2.0.0';
    public $displayName = 'Briqpay Payments';
    public $active = true;
    public $id = 1;

    public function registerHook($hookName, $shopList = null, $position = null): bool
    {
        return true;
    }

    public static function getInstanceByName($moduleName)
    {
        return new self();
    }
}

class PaymentModule extends Module
{
}

if (!function_exists('pSQL')) {
    function pSQL($string, $htmlOk = false, $boolBypassSanitize = false)
    {
        return addslashes((string) $string);
    }
}

if (!function_exists('bqSQL')) {
    function bqSQL($string)
    {
        return str_replace('`', '\\`', (string) $string);
    }
}
