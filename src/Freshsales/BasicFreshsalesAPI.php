<?php

namespace ianfortier;

use Closure;
use stdClass;
use Exception;
use Psr\Log\LogLevel;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Uri;
use GuzzleHttp\Middleware;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
use Psr\Log\LoggerInterface;
use GuzzleHttp\Promise\Promise;
use Psr\Log\LoggerAwareInterface;
use Psr\Http\Message\ResponseInterface;
use GuzzleHttp\Exception\RequestException;

class BasicFreshsalesAPI implements LoggerAwareInterface
{
    /**
     * Largely inspired by osiset/Basic-Shopify-API
     *
    */

    /**
     * The key to use for logging (prefix for filtering).
     *
     * @var string
    */
    const LOG_KEY = '[BasicFreshsalesAPI]';

    /**
     * The Guzzle client.
     *
     * @var \GuzzleHttp\Client
     */
    protected $client;

    /**
     * The current API call limits from last request.
     *
     * @var array
     */
    protected $apiCallLimits = [
        'rest'  => [
            'left'  => 0,
            'made'  => 0,
            'limit' => 2000,
        ],
    ];

    /**
     * If rate limiting is enabled.
     *
     * @var bool
     */
    protected $rateLimitingEnabled = false;

    /**
     * The rate limiting cycle (in ms).
     *
     * @var int
     */
    protected $rateLimitCycle = 1 * 1000;

    /**
     * The rate limiting cycle buffer (in ms).
     *
     * @var int
     */
    protected $rateLimitCycleBuffer = 0.1 * 1000;

    /**
     * Request timestamp for every new call.
     * Used for rate limiting.
     *
     * @var int
     */
    protected $requestTimestamp;

    /**
     * The logger.
     *
     * @var LoggerInterface
     */
    protected $logger;


    /**
     * Constructor.
     *
     * @param array $clientOptions Additional options to pass to the Guzzle client.
     *
     * @return self
     */
    public function __construct(array $options = [])
    {
        $stack = HandlerStack::create();
        // Create a default Guzzle client with our stack
        $this->client = new Client(
            array_merge(
                [
                    'handler'  => $stack,
                    'headers'  => [
                        'Authorization' => "Token token=".ENV('FRESHSALES_APIKEY'),
                        'Accept'       => 'application/json',
                        'Content-Type' => 'application/json',
                    ],
                ],
                $options
            )
        );

        return $this;
    }

    /**
     * Sets the Guzzle client for the API calls (allows for override with your own).
     *
     * @param \GuzzleHttp\Client $client The Guzzle client
     *
     * @return self
     */
    public function setClient(Client $client): self
    {
        $this->client = $client;
        return $this;
    }

    /**
     * Set the rate limiting state to enabled.
     *
     * @param int|null $cycle  The rate limiting cycle (in ms, default 500ms).
     * @param int|null $buffer The rate limiting cycle buffer (in ms, default 100ms).
     *
     * @return self
     */
    public function enableRateLimiting(int $cycle = null, int $buffer = null): self
    {
        $this->rateLimitingEnabled = true;

        if (!is_null($cycle)) {
            $this->rateLimitCycle = $cycle;
        }

        if (!is_null($cycle)) {
            $this->rateLimitCycleBuffer = $buffer;
        }

        return $this;
    }

    /**
     * Set the rate limiting state to disabled.
     *
     * @return self
     */
    public function disableRateLimiting(): self
    {
        $this->rateLimitingEnabled = false;
        return $this;
    }

    /**
     * Determines if rate limiting is enabled.
     *
     * @return bool
     */
    public function isRateLimitingEnabled(): bool
    {
        return $this->rateLimitingEnabled === true;
    }

    /**
     * Returns the current API call limits.
     *
     * @param string|null $key The key to grab (left, made, limit, etc).
     *
     * @throws Exception When attempting to grab a key that doesn't exist.
     *
     * @return mixed Either whole array of call data or single key.
     */
    public function getApiCalls(string $type = 'rest', string $key = null)
    {
        if ($key) {
            $keys = array_keys($this->apiCallLimits[$type]);
            if (!in_array($key, $keys)) {
                // No key like that in array
                throw new Exception('Invalid API call limit key. Valid keys are: '.implode(', ', $keys));
            }

            // Return the key value requested
            return $this->apiCallLimits[$type][$key];
        }

        // Return all the values
        return $this->apiCallLimits[$type];
    }


    /**
     * Returns the base URI to use.
     *
     * @throws Exception for missing domain.
     *
     * @return Uri
     */
    public function getBaseUri(): Uri
    {
        $domain = ENV('FRESHSALES_DOMAIN');
        if ($domain === null) {
            // Domain is required
            throw new Exception('Freshsales domain missing for API calls');
        }


        return new Uri("https://{$domain}");
    }

    /**
     * Runs a request to the Freshsales API.
     *
     * @param string     $type    The type of request... GET, POST, PUT, DELETE.
     * @param string     $path    The Freshsales API path... /leads/xxxx.
     * @param array|null $params  Optional parameters to send with the request.
     * @param array      $headers Optional headers to append to the request.
     * @param bool       $sync    Optionally wait for the request to finish.
     *
     * @throws Exception
     *
     * @return stdClass|Promise An Object of the Guzzle response, and JSON-decoded body OR a promise.
     */
    public function rest(string $type, string $path, array $params = null, array $headers = [], bool $sync = true)
    {
        // Check the rate limit before firing the request
        $this->handleRateLimiting();

        // Update the timestamp of the request
        $tmpTimestamp = $this->updateRequestTime();

        // Build URI and try the request
        $uri = $this->getBaseUri()->withPath($path);

        // Build the request parameters for Guzzle
        $guzzleParams = [];
        if ($params !== null) {
            $guzzleParams[strtoupper($type) === 'GET' ? 'query' : 'json'] = $params;
        }

        $this->log("[{$uri}:{$type}] Request Params: ".json_encode($params));

        // Add custom headers
        if (count($headers) > 0) {
            $guzzleParams['headers'] = $headers;
            $this->log("[{$uri}:{$type}] Request Headers: ".json_encode($headers));
        }

        // Request function
        $requestFn = function () use ($sync, $type, $uri, $guzzleParams) {
            $fn = $sync ? 'request' : 'requestAsync';
            return $this->client->{$fn}($type, $uri, $guzzleParams);
        };

        /**
         * Success function.
         *
         * @param ResponseInterface $resp The response object.
         *
         * @return stdClass
         */
        $successFn = function (ResponseInterface $resp) use ($uri, $type, $tmpTimestamp): stdClass {
            $rawBody = $resp->getBody();
            $status = $resp->getStatusCode();

            $this->updateRestCallLimits($resp);
            $this->log("[{$uri}:{$type}] {$status}: ".json_encode($rawBody));

            // Return Guzzle response and JSON-decoded body
            return (object) [
                'errors'     => false,
                'status'     => $status,
                'response'   => $resp,
                'body'       => $this->jsonDecode($rawBody, true),
                'bodyObject'  => $this->jsonDecode($rawBody, false),
                'timestamps' => [$tmpTimestamp, $this->requestTimestamp],
            ];
        };

        /**
         * Error function.
         *
         * @param RequestException $e The request exception object.
         *
         * @return stdClass
         */
        $errorFn = function (RequestException $e) use ($uri, $type, $tmpTimestamp): stdClass {
            $resp = $e->getResponse();
            if ($resp) {
                $rawBody = $resp->getBody();
                $status = $resp->getStatusCode();

                $this->updateRestCallLimits($resp);
                $this->log("[{$uri}:{$type}] {$status} Error: {$rawBody}");

                // Build the error object
                $body = $this->jsonDecode($rawBody);
                $bodyArray = $this->jsonDecode($rawBody, true);
                if ($body !== null) {
                    if (property_exists($body, 'errors')) {
                        $body = $body->errors;
                        $bodyArray = $bodyArray['errors'];
                    } elseif (property_exists($body, 'error')) {
                        $body = $body->error;
                        $bodyArray = $bodyArray['error'];
                    } else {
                        $body = null;
                        $bodyArray = null;
                    }
                }
            } else {
                $status = null;
                $body = null;
                $bodyArray = null;

                $this->log("[{$uri}:{$type}] Unknown Error: {$e->getMessage()}");
            }

            return (object) [
                'errors'     => true,
                'status'     => $status,
                'response'   => $resp,
                'body'       => $body,
                'bodyArray'  => $bodyArray,
                'exception'  => $e,
                'timestamps' => [$tmpTimestamp, $this->requestTimestamp],
            ];
        };

        if ($sync === false) {
            // Async request
            $promise = $requestFn();
            return $promise->then($successFn, $errorFn);
        } else {
            // Sync request (default)
            try {
                return $successFn($requestFn());
            } catch (RequestException $e) {
                return $errorFn($e);
            }
        }
    }


    /**
     * Sets a logger instance on the object.
     *
     * @param LoggerInterface $logger The logger instance.
     *
     * @return self
     */
    public function setLogger(LoggerInterface $logger): self
    {
        $this->logger = $logger;
        return $this;
    }

    /**
     * Log a message to the logger.
     *
     * @param string $msg   The message to send.
     * @param int    $level The level of message.
     *
     * @return bool
     */
    public function log(string $msg, string $level = LogLevel::DEBUG): bool
    {
        if ($this->logger === null) {
            // No logger, do nothing
            return false;
        }

        // Call the logger by level and pass the message
        call_user_func([$this->logger, $level], self::LOG_KEY.' '.$msg);
        return true;
    }

    /**
     * Decodes the JSON body.
     *
     * @param string $json    The JSON body.
     * @param bool   $asArray Decode as an array.
     *
     * @return stdClass|array The decoded JSON.
     */
    protected function jsonDecode($json, bool $asArray = false)
    {
        // From firebase/php-jwt
        if (!(defined('JSON_C_VERSION') && PHP_INT_SIZE > 4)) {
            /**
             * In PHP >=5.4.0, json_decode() accepts an options parameter, that allows you
             * to specify that large ints (like Steam Transaction IDs) should be treated as
             * strings, rather than the PHP default behaviour of converting them to floats.
             */
            $obj = json_decode($json, $asArray, 512, JSON_BIGINT_AS_STRING);
        } else {
            // @codeCoverageIgnoreStart
            /**
             * Not all servers will support that, however, so for older versions we must
             * manually detect large ints in the JSON string and quote them (thus converting
             * them to strings) before decoding, hence the preg_replace() call.
             * Currently not sure how to test this so I ignored it for now.
             */
            $maxIntLength = strlen((string) PHP_INT_MAX) - 1;
            $jsonWithoutBigints = preg_replace('/:\s*(-?\d{'.$maxIntLength.',})/', ': "$1"', $json);
            $obj = json_decode($jsonWithoutBigints, $asArray);
            // @codeCoverageIgnoreEnd
        }

        return $obj;
    }


    /**
     * Handles rate limiting (if enabled).
     *
     * @return void
     */
    protected function handleRateLimiting(): void
    {
        if (!$this->isRateLimitingEnabled() || !$this->requestTimestamp) {
            return;
        }

        // Calculate in milliseconds the duration the API call took
        $duration = round(microtime(true) - $this->requestTimestamp, 3) * 1000;
        $waitTime = ($this->rateLimitCycle - $duration) + $this->rateLimitCycleBuffer;

        if ($waitTime > 0) {
            // Do the sleep for X mircoseconds (convert from milliseconds)
            $this->log('Rest rate limit hit');
            usleep($waitTime * 1000);
        }
    }

    /**
     * Updates the request time.
     *
     * @return float|null
     */
    protected function updateRequestTime(): ?float
    {
        $tmpTimestamp = $this->requestTimestamp;
        $this->requestTimestamp = microtime(true);

        return $tmpTimestamp;
    }

    /**
     * Updates the REST API call limits from Freshsales headers.
     *
     * @param ResponseInterface $resp The response from the request.
     *
     * @return void
     */
    protected function updateRestCallLimits(ResponseInterface $resp): void
    {
        // Grab the API call limit header returned from Freshsales
        $callLimitRemaining = $resp->getHeader('x-RateLimit-Remaining')[0];
        $callLimitTotal = $resp->getHeader('x-RateLimit-Limit')[0];
        if (!$callLimitRemaining) {
            return;
        }

        $this->apiCallLimits['rest'] = [
            'left'  => (int) $callLimitRemaining,
            'made'  => (int) $callLimitTotal - $callLimitRemaining,
            'limit' => (int) $callLimitTotal,
        ];
    }
}
