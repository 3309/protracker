<?php

namespace App\Services;

use App\Exceptions\AlpacaServiceException;
use App\Support\Alpaca\AlpacaResult;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;

/**
 * Reads from the separate Node.js service over an Alpaca paper account.
 *
 * Read-only by design: the only request it can make is a GET, and it has no
 * way to place, change or cancel an order. Nothing is saved to the database;
 * Alpaca data is kept apart from the Fidelity and Robinhood records, and only
 * market data is cached, briefly. The token is sent in a header and only ever
 * to this computer; it is never logged or put in a message.
 */
class AlpacaService
{
    public const string SYMBOL_PATTERN = '/^[A-Z][A-Z0-9.-]{0,9}$/';

    /** The most orders the service returns in one request. */
    public const int MAX_ORDERS = 500;

    /** One batch of a chain across all expirations: a whole chain can run to thousands. */
    public const int OPTION_CONTRACTS_PER_BATCH = 500;

    /** One expiration is loaded whole, up to 3 pages of 1,000. */
    private const int CONTRACTS_PER_PAGE = 1000;

    private const int MAX_PAGES_FOR_ONE_EXPIRATION = 3;

    /** The most the service reads in one request, to list a symbol's expirations. */
    private const int MAX_PAGES_TO_LIST_EXPIRATIONS = 10;

    private const array LOCAL_HOSTS = ['127.0.0.1', 'localhost', '[::1]'];

    public function __construct(
        private string $baseUrl,
        #[SensitiveParameter] private string $token,
        private int $connectTimeoutSeconds = 2,
        private int $timeoutSeconds = 8,
        private int $quoteCacheSeconds = 15,
        private int $optionChainCacheSeconds = 60,
        private int $optionExpirationsCacheSeconds = 3600,
    ) {}

    /**
     * Shows that the service is running. It does not show whether Alpaca is
     * reachable; account() does.
     *
     * @throws AlpacaServiceException
     */
    public function health(): AlpacaResult
    {
        $result = $this->get('/api/health');

        if (($result->data['status'] ?? null) !== 'ok') {
            throw AlpacaServiceException::malformed();
        }

        if (($result->data['mode'] ?? null) !== 'paper' || ($result->data['readOnly'] ?? null) !== true) {
            throw AlpacaServiceException::notPaper();
        }

        return $result;
    }

    /**
     * @throws AlpacaServiceException
     */
    public function account(): AlpacaResult
    {
        $result = $this->get('/api/alpaca/account');

        foreach (['cash', 'buyingPower', 'equity'] as $field) {
            if (! is_numeric($result->data[$field] ?? null)) {
                throw AlpacaServiceException::malformed();
            }
        }

        return $result;
    }

    /**
     * All open positions, whatever the asset class.
     *
     * @throws AlpacaServiceException
     */
    public function positions(): AlpacaResult
    {
        return $this->ensureListOfRows($this->get('/api/alpaca/positions'), 'symbol');
    }

    /**
     * @throws AlpacaServiceException
     */
    public function openOrders(): AlpacaResult
    {
        return $this->ensureListOfRows(
            $this->get('/api/alpaca/orders', ['status' => 'open', 'limit' => self::MAX_ORDERS]),
            'id',
        );
    }

    /**
     * @throws AlpacaServiceException
     */
    public function quote(string $symbol): AlpacaResult
    {
        $symbol = $this->normalizeSymbol($symbol);

        return $this->remember($this->quoteCacheKey($symbol), $this->quoteCacheSeconds, function () use ($symbol): AlpacaResult {
            $result = $this->get('/api/market/quote/'.rawurlencode($symbol));

            if (! is_string($result->data['symbol'] ?? null) || ! is_string($result->data['dataStatus'] ?? null)) {
                throw AlpacaServiceException::malformed();
            }

            return $result;
        });
    }

    /**
     * @throws AlpacaServiceException
     */
    public function forgetQuote(string $symbol): void
    {
        Cache::forget($this->quoteCacheKey($this->normalizeSymbol($symbol)));
    }

    /**
     * @param  string  $type  "call" or "put", or blank for both
     * @param  string  $expiration  YYYY-MM-DD, or blank for any
     * @param  string  $fromExpiration  YYYY-MM-DD to start from, or blank for the nearest; ignored with $expiration
     *
     * @throws AlpacaServiceException
     */
    public function optionChain(string $symbol, string $type = '', string $expiration = '', string $fromExpiration = ''): AlpacaResult
    {
        $symbol = $this->normalizeSymbol($symbol);
        $this->ensureValidChainFilters($type, $expiration, $fromExpiration);
        $fromExpiration = $expiration === '' ? $fromExpiration : '';

        $query = array_filter([
            'type' => $type,
            'expiration_date' => $expiration,
            'expiration_date_gte' => $fromExpiration,
            'limit' => $expiration === '' ? self::OPTION_CONTRACTS_PER_BATCH : self::CONTRACTS_PER_PAGE,
            'max_pages' => $expiration === '' ? 1 : self::MAX_PAGES_FOR_ONE_EXPIRATION,
        ], fn (string|int $value): bool => $value !== '');

        return $this->remember($this->optionChainCacheKey($symbol, $type, $expiration, $fromExpiration), $this->optionChainCacheSeconds, fn (): AlpacaResult => $this->ensureListOfRows(
            $this->get('/api/market/options/'.rawurlencode($symbol), $query),
            'symbol',
        ));
    }

    /**
     * @throws AlpacaServiceException
     */
    public function forgetOptionChain(string $symbol, string $type = '', string $expiration = '', string $fromExpiration = ''): void
    {
        Cache::forget($this->optionChainCacheKey($this->normalizeSymbol($symbol), $type, $expiration, $expiration === '' ? $fromExpiration : ''));
    }

    /**
     * The expiration dates of a symbol's options, earliest first, in data. The
     * service has no endpoint for only the dates, so the chain is read once on
     * the server and only the dates are kept. meta.truncated is true when the
     * chain was too long to read whole, so later dates may be missing.
     *
     * @throws AlpacaServiceException
     */
    public function optionExpirations(string $symbol): AlpacaResult
    {
        $symbol = $this->normalizeSymbol($symbol);

        return $this->remember($this->optionExpirationsCacheKey($symbol), $this->optionExpirationsCacheSeconds, function () use ($symbol): AlpacaResult {
            $chain = $this->get('/api/market/options/'.rawurlencode($symbol), [
                'limit' => self::CONTRACTS_PER_PAGE,
                'max_pages' => self::MAX_PAGES_TO_LIST_EXPIRATIONS,
            ]);

            $dates = $chain->meta['expirations'] ?? null;

            if (! is_array($dates) || ! array_is_list($dates)) {
                throw AlpacaServiceException::malformed();
            }

            foreach ($dates as $date) {
                if (! is_string($date) || preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                    throw AlpacaServiceException::malformed();
                }
            }

            sort($dates);

            return new AlpacaResult($dates, ['truncated' => $chain->meta['truncated'] ?? null], $chain->fetchedAt);
        });
    }

    /**
     * @throws AlpacaServiceException
     */
    public function forgetOptionExpirations(string $symbol): void
    {
        Cache::forget($this->optionExpirationsCacheKey($this->normalizeSymbol($symbol)));
    }

    /**
     * Checked here as well as on the page, because the symbol becomes part of
     * the request address.
     *
     * @throws AlpacaServiceException
     */
    private function normalizeSymbol(string $symbol): string
    {
        $symbol = strtoupper(trim($symbol));

        if (preg_match(self::SYMBOL_PATTERN, $symbol) !== 1) {
            throw AlpacaServiceException::invalidRequest();
        }

        return $symbol;
    }

    /**
     * @throws AlpacaServiceException
     */
    private function ensureValidChainFilters(string $type, string $expiration, string $fromExpiration): void
    {
        if (! in_array($type, ['', 'call', 'put'], true)) {
            throw AlpacaServiceException::invalidRequest();
        }

        foreach ([$expiration, $fromExpiration] as $date) {
            if ($date !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1) {
                throw AlpacaServiceException::invalidRequest();
            }
        }
    }

    private function quoteCacheKey(string $symbol): string
    {
        return "alpaca:quote:{$symbol}";
    }

    private function optionExpirationsCacheKey(string $symbol): string
    {
        return "alpaca:option-expirations:{$symbol}";
    }

    private function optionChainCacheKey(string $symbol, string $type, string $expiration, string $fromExpiration): string
    {
        return "alpaca:option-chain:{$symbol}:{$type}:{$expiration}:{$fromExpiration}";
    }

    /**
     * Failures are not cached, so a retry always tries again.
     *
     * @param  callable(): AlpacaResult  $fetch
     *
     * @throws AlpacaServiceException
     */
    private function remember(string $key, int $seconds, callable $fetch): AlpacaResult
    {
        $saved = $seconds > 0 ? Cache::get($key) : null;
        $cached = is_array($saved) ? AlpacaResult::fromCache($saved) : null;

        if ($cached !== null) {
            return $cached;
        }

        $result = $fetch();

        if ($seconds > 0) {
            Cache::put($key, $result->toCache(), $seconds);
        }

        return $result;
    }

    /**
     * @param  array<string, string|int>  $query
     *
     * @throws AlpacaServiceException
     */
    private function get(string $path, array $query = []): AlpacaResult
    {
        $this->ensureConfigured();

        try {
            $response = Http::withToken($this->token)
                ->acceptJson()
                ->withoutRedirecting()
                ->connectTimeout($this->connectTimeoutSeconds)
                ->timeout($this->timeoutSeconds)
                ->get(rtrim($this->baseUrl, '/').$path, $query);
        } catch (ConnectionException) {
            throw AlpacaServiceException::unreachable();
        }

        if (! $response->successful()) {
            throw $this->failureFrom($response);
        }

        $body = $response->json();

        if (! is_array($body) || ! is_array($body['data'] ?? null)) {
            throw AlpacaServiceException::malformed();
        }

        return new AlpacaResult($body['data'], is_array($body['meta'] ?? null) ? $body['meta'] : [], now()->toImmutable());
    }

    /**
     * @throws AlpacaServiceException
     */
    private function ensureConfigured(): void
    {
        if (trim($this->token) === '') {
            throw AlpacaServiceException::notConfigured();
        }

        if (! in_array(parse_url($this->baseUrl, PHP_URL_HOST), self::LOCAL_HOSTS, true)) {
            throw AlpacaServiceException::notLocal();
        }
    }

    /**
     * The token check is told apart from Alpaca rejecting the service's own
     * keys, which the service reports as a 502 with its own code.
     */
    private function failureFrom(Response $response): AlpacaServiceException
    {
        $code = $response->json('error.code');

        return match (true) {
            $response->status() === 401 => AlpacaServiceException::tokenRejected(),
            $response->status() === 429 => AlpacaServiceException::rateLimited(is_numeric($response->header('Retry-After')) ? (int) $response->header('Retry-After') : null),
            $code === 'market_data_unavailable' => AlpacaServiceException::noData(),
            $code === 'market_data_restricted' => AlpacaServiceException::dataRestricted(),
            $code === 'alpaca_auth_failed' => AlpacaServiceException::alpacaKeysRejected(),
            $code === 'alpaca_permission_denied' => AlpacaServiceException::alpacaPermissionDenied(),
            $code === 'alpaca_unreachable' => AlpacaServiceException::alpacaUnreachable(),
            $code === 'alpaca_error' => AlpacaServiceException::alpacaError(),
            $code === 'validation_failed' => AlpacaServiceException::invalidRequest(),
            default => AlpacaServiceException::unavailable(),
        };
    }

    /**
     * An empty list is a valid answer; anything but a list of rows is not.
     *
     * @throws AlpacaServiceException
     */
    private function ensureListOfRows(AlpacaResult $result, string $requiredField): AlpacaResult
    {
        if (! array_is_list($result->data)) {
            throw AlpacaServiceException::malformed();
        }

        foreach ($result->data as $row) {
            if (! is_array($row) || ! is_string($row[$requiredField] ?? null)) {
                throw AlpacaServiceException::malformed();
            }
        }

        return $result;
    }
}
