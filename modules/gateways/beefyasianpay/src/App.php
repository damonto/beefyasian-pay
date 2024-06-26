<?php

namespace BeefyAsianPay;

use BeefyAsianPay\Exceptions\NoAddressAvailable;
use BeefyAsianPay\Models\BeefyAsianPayInvoice;
use BeefyAsianPay\Models\Invoice;
use BeefyAsianPay\Models\Transaction;
use Carbon\Carbon;
use GuzzleHttp\Client;
use WHMCS\Database\Capsule;
use Illuminate\Support\Collection;
use RuntimeException;
use Smarty;
use Throwable;

class App
{
    /**
     * USDT Addresses.
     *
     * @var  array
     */
    protected $addresses = [];

    /**
     * Timeout.
     *
     * @var  int
     */
    protected $timeout = 30;

    /**
     * The payment gateway fields.
     *
     * @var string[]
     */
    protected $config = [
        'FriendlyName' => [
            'Type' => 'System',
            'Value' => '肉償付',
        ],
        'addresses' => [
            'FriendlyName' => 'USDT Addresses',
            'Description' => 'ADDR|TRC20 or ADDR|POLYGON (default:TRC20)',
            'Type' => 'textarea',
            'Rows' => '10',
            'Cols' => '30',
        ],
        'polygonscan_api_key' => [
            'FriendlyName' => 'PolygonScan API Key',
            'Type' => 'text',
            'Size' => '50',
            'Description' => 'Get your API key from <a href="https://polygonscan.com/myapikey" target="_blank">here</a>',
        ],
        'timeout' => [
            'FriendlyName' => 'Timeout',
            'Type' => 'text',
            'Value' => 30,
            'Description' => 'Minutes'
        ]
    ];

    /**
     * Smarty template engine.
     *
     * @var Smarty
     */
    protected $smarty;

    /**
     * Create a new instance.
     *
     * @param   array  $params
     *
     * @return  void
     */
    public function __construct(array $params = [])
    {
        if (!function_exists('getGatewayVariables')) {
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'init.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/gatewayfunctions.php';
            require_once dirname(__DIR__, 3) . DIRECTORY_SEPARATOR . 'includes/invoicefunctions.php';
        } else {
            if (empty($params)) {
                try {
                    $params = getGatewayVariables('beefyasianpay');
                } catch (Throwable $e) {
                }
            }
        }

        $this->timeout = $params['timeout'] ?? 30;
        $this->addresses = $this->parseAddresses($params['addresses'] ?? '');

        $this->smarty = new Smarty();
        $this->smarty->setTemplateDir(BEEFYASIAN_PAY_ROOT . DIRECTORY_SEPARATOR . 'templates');
        $this->smarty->setCompileDir(WHMCS_ROOT . DIRECTORY_SEPARATOR . 'templates_c');
    }

    /**
    * Convert the given amount to USD based on the currency rate from the database.
    *
    * @param float $amount The amount to be converted.
    * @param string $currency The original currency of the amount.
    * @return float The converted amount in USD.
    */
    protected function convertToUSD(float $amount, string $currency): float
    {
        if ($currency == 'USD') {
            return $amount;
        }
        //check if usd currency is exsits
        $isUsdCurrencyExists = Capsule::table("tblcurrencies")->where("code", "USD")->exists();
        if (!$isUsdCurrencyExists) {
            throw new RuntimeException("no USD currency exists in WHMCS");
        }
        //make sure that the default currency is USD
        $isUsdCurrencyDefault = Capsule::table("tblcurrencies")->where("code", "USD")->value("default");
        
        if ($isUsdCurrencyDefault != '1') {
            
            $currencyRate = Capsule::table("tblcurrencies")->where("code", $currency)->value("rate");
            return $amount * $currencyRate;
        } else if (Capsule::table("tblcurrencies")->where("code", $currency)->value("default") == '1') {
            //If the invoice currency is default, convert the amount by dividing by the currency rate
            $currencyRate = Capsule::table("tblcurrencies")->where("code", "USD")->value("rate");
            return $amount / $currencyRate;
        } else {
            throw new RuntimeException("The default currency is not USD");
        }
    }

    /**
    * Convert the given amount of invoice currency to USD given on the currency rate from the database.
    *
    * @param float $amount The amount to be converted.
    * @param string $currency The original currency of the amount.
    * @return float The converted amount in USD.
    */
    protected function convertToInvoiceCurrency(float $amount,  string $currency): float
    {   
        if ($currency == 'USD') {
            return $amount;
        }
        //check if usd currency is exsits
        $isUsdCurrencyExists = Capsule::table("tblcurrencies")->where("code", "USD")->exists();
        if (!$isUsdCurrencyExists) {
            throw new RuntimeException("no USD currency exists in WHMCS");
        }
        //make sure that the default currency is USD
        $isUsdCurrencyDefault = Capsule::table("tblcurrencies")->where("code", "USD")->value("default");
        
        if ($isUsdCurrencyDefault != '1') {
            
            $currencyRate = Capsule::table("tblcurrencies")->where("code", $currency)->value("rate");
            return $amount * $currencyRate;
        } else if (Capsule::table("tblcurrencies")->where("code", $currency)->value("default") == '1') {
            //If the invoice currency is default, convert the amount by dividing by the currency rate
            $currencyRate = Capsule::table("tblcurrencies")->where("code", "USD")->value("rate");
            return $amount / $currencyRate;
        } else {
            //this is a collection behavior, which means should not encounter error. But should not be here.
            return $amount;
        }
    }
    /**
     * Parse addresses.
     *
     * @param   string  $rawAddress
     *
     * @return  array
     */
    protected function parseAddresses(string $rawAddress): array
    {
        $lines = array_filter(preg_split("/\r\n|\n|\r/", $rawAddress ?? ''));
        $addresses = [
            'TRC20' => [],
            'POLYGON' => [],
        ];

        foreach ($lines as $line) {
            $line = trim($line);
            if (empty($line)) {
                continue;
            }

            $address = explode('|', $line);
            $addresses[$address[1] ?? 'TRC20'][] = $address[0];
        }

        return $addresses;
    }

    /**
     * Fetch smarty renderred template.
     *
     * @param   string  $viewName
     * @param   array   $arguments
     *
     * @return  string
     */
    protected function view(string $viewName, array $arguments = [])
    {
        foreach ($arguments as $name => $variable) {
            $this->smarty->assign($name, $variable);
        }

        return $this->smarty->fetch($viewName);
    }

    /**
     * Install BeefyAsianPay.
     *
     * @return  string[]
     */
    public function install()
    {
        $this->runMigrations();

        return $this->config;
    }

    /**
     * Run beefy asian pay migrations.
     *
     * @return  void
     */
    protected function runMigrations()
    {
        $migrationPath = __DIR__ . DIRECTORY_SEPARATOR . 'Migrations';
        $migrations = array_diff(scandir($migrationPath), ['.', '..']);

        foreach ($migrations as $migration) {
            require_once $migrationPath . DIRECTORY_SEPARATOR . $migration;

            $migrationName = str_replace('.php', '', $migration);

            (new $migrationName)->execute();
        }
    }

    /**
     * Render payment html.
     *
     * @param   array  $params
     *
     * @return  mixed
     */
    public function render(array $params)
    {
        switch ($_GET['act']) {
            case 'invoice_status':
                $this->renderInvoiceStatusJson($params);
            case 'create':
                $this->createBeefyAsianPayInvoice($_GET['chain'] ?? 'TRC20', $params);
            case 'switch_chain':
                return $this->switchChain($_GET['chain'], $params);
            default:
                return $this->renderPaymentHTML($params);
        }
    }

    /**
     * Switch chain.
     *
     * @param   string  $chain
     * @param   array   $params
     *
     * @return  void
     */
    protected function switchChain(string $chain, array $params)
    {
        $beefyInvoice = (new BeefyAsianPayInvoice())->firstValidByInvoiceId($params['invoiceid']);
        if ($beefyInvoice['chain'] !== $chain) {
            (new BeefyAsianPayInvoice())->dissociate($beefyInvoice['to_address'], $params['invoiceid']);

            return $this->createBeefyAsianPayInvoice($chain, $params);
        }

        $this->json([
            'status' => false,
            'error' => 'The invoice has been associated with a USDT address please refresh the invoice page.',
        ]);
    }

    /**
     * Create beefy asian pay invoice.
     *
     * @param   string  $chain
     * @param   array   $params
     *
     * @return  void
     */
    protected function createBeefyAsianPayInvoice(string $chain, array $params)
    {
        try {
            $invoice = (new Invoice())->find($params['invoiceid']);

            if (mb_strtolower($invoice['status']) === 'paid') {
                $this->json([
                    'status' => false,
                    'error' => 'The invoice has been paid in full.'
                ]);
            }

            $address = $this->getAvailableAddress($chain, $params['invoiceid']);

            $this->json([
                'status' => true,
                'chain' => $chain,
                'address' => $address,
            ]);
        } catch (Throwable $e) {
            $this->json([
                'status' => false,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Get then invoice status json.
     *
     * @param   array   $params
     *
     * @return  void
     */
    protected function renderInvoiceStatusJson(array $params)
    {
        $beefyInvoice = (new BeefyAsianPayInvoice())->firstValidByInvoiceId($params['invoiceid']);
        if ($beefyInvoice) {
            $invoice = (new Invoice())->with('transactions')->find($params['invoiceid']);
            $this->checkTransaction($beefyInvoice);
            $beefyInvoice = $beefyInvoice->refresh();

            if (mb_strtolower($invoice['status']) === 'unpaid') {
                if ($beefyInvoice['expires_on']->subMinutes(3)->lt(Carbon::now())) {
                    $beefyInvoice->renew($this->timeout);
                }

                $beefyInvoice = $beefyInvoice->refresh();
            }

            $json = [
                'status' => $invoice['status'],
                'chain' => $beefyInvoice['chain'],
                'amountin' => $invoice['transactions']->sum('amountin'),
                'valid_till' => $beefyInvoice['expires_on']->toDateTimeString(),
            ];

            $this->json($json);
        }

        $this->json([
            'status' => false,
            'error' => 'invoice does not exists',
        ]);
    }

    /**
     * Responed with JSON.
     *
     * @param   array  $json
     *
     * @return  void
     */
    protected function json(array $json)
    {
        http_response_code(200);
        header('Content-Type: application/json');
        echo json_encode($json);

        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            exit();
        }
    }

    /**
     * Render pay with usdt html.
     *
     * @param   array   $params
     *
     * @return  string
     */
    protected function renderPaymentHTML(array $params): string
    {
        $beefyInvoice = new BeefyAsianPayInvoice();

        $currectChains = array_filter(array_keys($this->addresses), function ($chain) {
            return count($this->addresses[$chain]) > 0;
        });

        $supportedChains = [];
        foreach ($currectChains as $value) {
            $supportedChains[$value] = $value == 'TRC20' ? 'USDT ( Tron / TRC20 )' : 'USDT ( Polygon / MATIC / ERC20 )';
        }

        if ($validAddress = $beefyInvoice->validInvoice($params['invoiceid'])) {
            $validAddress->renew($this->timeout);
            $validTill = Carbon::now()->addMinutes($this->timeout)->toDateTimeString();
             // convert the invoice amount to USD
             if ($params['currency'] == '') {
                // if the currency is empty, the amount will not shown in the invoice
                $convertedAmount = 0;
             }
            $convertedAmount = $this->convertToUSD($params['amount'],$params['currency']);

            return $this->view('payment.tpl', [
                'address' => $validAddress['to_address'],
                'amount' => $convertedAmount,
                'chain' => $validAddress['chain'],
                'validTill' => $validTill,
                'supportedChains' => $supportedChains,
            ]);
        } else {
            return $this->view('pay_with_usdt.tpl', [
                'supportedChains' => $supportedChains,
            ]);
        }
    }

    /**
     * Remove expired invoices.
     *
     * @return  void
     */
    public function cron()
    {
        $this->checkPaidInvoice();

        (new BeefyAsianPayInvoice())->markExpiredInvoiceAsReleased();
    }

    /**
     * Check paid invoices.
     *
     * @return  void
     */
    protected function checkPaidInvoice()
    {
        $invoices = (new BeefyAsianPayInvoice())->getValidInvoices();

        $invoices->each(function ($invoice) {
            $this->checkTransaction($invoice);
        });
    }

    /**
     * Check USDT Transaction.
     *
     * @param   BeefyAsianPayInvoice  $invoice
     *
     * @return  void
     */
    protected function checkTransaction(BeefyAsianPayInvoice $invoice)
    {
        $transactions = $invoice['chain'] === 'TRC20'
            ? $this->getTRC20Transactions($invoice['to_address'], $invoice['created_at'])
            : $this->getPolygonTransactions($invoice['to_address'], $invoice['created_at']);

        $transactions
            ->each(function ($transaction) use ($invoice) {
                $whmcsTransaction = (new Transaction())->firstByTransId($invoice['chain'] === 'TRC20' ? $transaction['transaction_id'] : $transaction['hash']);
                $whmcsInvoice = Invoice::find($invoice['invoice_id']);
                // If current invoice has been paid ignore it.
                if ($whmcsTransaction) {
                    return;
                }

                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    return;
                }
                
                
                $transactionAmount = $transaction['value'] / 1000000; // 转换为单位为USDT的金额
                if ($params['currency'] == '') {
                    // if the currency is empty, the amount will not converted.
                    $transactionAmount = $transactionAmount;
                } else {
                    $transactionAmount = $this->convertToInvoiceCurrency($transactionAmount,$params['currency']);
                }
                
                AddInvoicePayment(
                    $invoice['invoice_id'], // Invoice id
                    $invoice['chain'] === 'TRC20' ? $transaction['transaction_id'] : $transaction['hash'], // Transaction id
                    $actualAmount, // Paid amount
                    0, // Transaction fee
                    'beefyasianpay' // Gateway
                );

                logTransaction('BeefyAsianPay', $transaction, 'Successfully Paid');

                $whmcsInvoice = $whmcsInvoice->refresh();
                // If the invoice has been paid in full, release the address, otherwise renew it.
                if (mb_strtolower($whmcsInvoice['status']) === 'paid') {
                    $invoice->markAsPaid($transaction['from'], $invoice['chain'] === 'TRC20' ? $transaction['transaction_id'] : $transaction['hash']);
                } else {
                    $invoice->renew($this->timeout);
                }
            });
    }

    /**
     * Get polygon address transactions.
     *
     * @param   string  $address
     * @param   Carbon  $address
     *
     * @return  Collection
     */
    protected function getPolygonTransactions(string $address, Carbon $startDatetime): Collection
    {
        $http = new Client([
            'base_uri' => 'https://api.polygonscan.com',
            'timeout' => 30,
        ]);

        $params = getGatewayVariables('beefyasianpay');

        $response = $http->get("/api?module=account&action=tokentx&address={$address}&page=1&offset=10&sort=desc&apikey={$params['polygonscan_api_key']}']}");
        $response = json_decode($response->getBody()->getContents(), true);
        if ($response['message'] !== 'OK') {
            throw new RuntimeException($response['message']);
        }

        return (new Collection($response['result']))->filter(function ($transaction) use ($startDatetime, $address) {
            return $transaction['timeStamp'] >= $startDatetime->getTimestamp()
                && strtolower($transaction['to']) === strtolower($address)
                && intval($transaction['confirmations']) >= 25;
        });
    }

    /**
     * Get TRC 20 address transactions.
     *
     * @param   string  $address
     * @param   Carbon  $address
     *
     * @return  Collection
     */
    protected function getTRC20Transactions(string $address, Carbon $startDatetime): Collection
    {
        $http = new Client([
            'base_uri' => 'https://api.trongrid.io',
            'timeout' => 30,
        ]);

        $response = $http->get("/v1/accounts/{$address}/transactions/trc20", [
            'query' => [
                'limit' => 5,
                'only_to' => true,
                'only_confirmed' => true,
                'contract_address' => 'TR7NHqjeKQxGTCi8q8ZY4pL8otSzgjLj6t',
                'min_timestamp' => $startDatetime->getTimestamp() * 1000,
            ],
        ]);

        $response = json_decode($response->getBody()->getContents(), true);

        return new Collection($response['data']);
    }

    /**
     * Get an available usdt address.
     *
     * @param   string  $chain
     * @param   int     $invoiceId
     *
     * @return  string
     *
     * @throws  NoAddressAvailable
     * @throws  RuntimeException
     */
    protected function getAvailableAddress(string $chain, int $invoiceId): string
    {
        $beefyInvoice = new BeefyAsianPayInvoice();

        if ($beefyInvoice->firstValidByInvoiceId($invoiceId)) {
            throw new RuntimeException("The invoice has been associated with a USDT address please refresh the invoice page.");
        }

        $inUseAddresses = $beefyInvoice->inUse()->get(['to_address']);

        $availableAddresses = array_values(array_diff($this->addresses[$chain], $inUseAddresses->pluck('to_address')->toArray()));

        if (count($availableAddresses) <= 0) {
            throw new NoAddressAvailable('no available address please try again later.');
        }

        $address = $availableAddresses[array_rand($availableAddresses)];
        $beefyInvoice->associate($chain, $address, $invoiceId, $this->timeout);

        return $address;
    }
}
