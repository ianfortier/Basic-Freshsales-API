# Basic Freshsales API

A simple API wrapper for Freshsales using Guzzle. It supports both the REST API provided by Freshsales, and basic rate limiting abilities.
Also supported: asynchronous requests through Guzzle's promises.

This library required PHP >= 7.

PS: This project is largely inspired (aka copy <3 ) from the MIT [osiset/Basic-Shopify-API](https://github.com/osiset/Basic-Shopify-API/blob/master/LICENSE).

## Table of Contents
  * [Installation](#installation)
  * [Usage](#usage)
        * [REST (sync)](#rest-sync)
        * [REST (async)](#rest-async)
      * [Making requests](#making-requests)
            * [If sync is true (regular rest call):](#if-sync-is-true-regular-rest-call)
            * [If sync is false (restAsync call):](#if-sync-is-false-restasync-call)
      * [Checking API limits](#checking-api-limits)
      * [Rate Limiting](#rate-limiting)
        * [Enable Rate Limiting](#enable-rate-limiting)
        * [Disabiling Rate Limiting](#disabiling-rate-limiting)
        * [Checking Rate Limiting Status](#checking-rate-limiting-status)
        * [Getting Timestamps](#getting-timestamps)
      * [Errors](#errors)
      * [Logging](#logging)
  * [Documentation](#documentation)
  * [LICENSE](#license)

## Installation

The recommended way to install is [through composer](http://packagist.org).

    composer require ianfortier/basic-freshsales-api

## Usage

Add `use ianfortier\BasicFreshsalesAPI;` to your imports.

#### REST (sync)

For REST calls, the shop domain and access token are required.

```php
$api = new BasicFreshsaleAPI();

// Now run your requests...
$resul = $api->rest(...);
```

#### REST (async)

For REST calls, the shop domain and access token are required.

```php
$api = new BasicFreshsaleAPI();

// Now run your requests...
$promise = $api->restAsync(...);
$promise->then(function ($result) {
  // ...
});
```

### Making requests

#### REST

Requests are made using Guzzle.

```php
$api->rest(string $type, string $path, array $params = null, array $headers = [], bool $sync = true);
```

+ `type` refers to GET, POST, PUT, DELETE, etc
+ `path` refers to the API path, example: `/api/leads`
+ `params` refers to an array of params you wish to pass to the path, examples: `['email' => 'customer@email.com']`
+ `headers` refers to an array of custom headers you would like to optionally send with the request, example: `['X-Freshsales-Test' => '123']`
+ `sync` refers to if the request should be synchronous or asynchronous.

You can use the alias `restAsync` to skip setting `sync` to `false`.

##### If sync is true (regular rest call):

The return value for the request will be an object containing:

+ `response` the full Guzzle response object
+ `body` the JSON decoded response body (array)
+ `bodyObject` the JSON decoded response body (stdClass)

*Note*: `request()` will alias to `rest()` as well.

##### If sync is false (restAsync call):

The return value for the request will be a Guzzle promise which you can handle on your own.

The return value for the promise will be an object containing:

+ `response` the full Guzzle response object
+ `body` the JSON decoded response body (array)
+ `bodyObject` the JSON decoded response body (stdClass)

```php
$promise = $api->restAsync(...);
$promise->then(function ($result) {
  // `response` and `body` available in `$result`.
});
```

##### Passing additional request options

If you'd like to pass additional request options to the Guzzle client created, pass them as the second argument of the constructor.

```php
$api = BasicFreshsaleAPI(true, ['connect_timeout' => 3.0]);
```

In the above, the array in the second argument will be merged into the Guzzle client created.

### Checking API limits

After each request is made, the API call limits are updated. To access them, simply use:

```php
// Returns an array of left, made, and limit.
// Example: ['left' => 79, 'made' => 1, 'limit' => 80]
$limits = $api->getApiCalls('rest');
```

### Rate Limiting

This library comes with a built-in basic rate limiter, disabled by default. It will sleep for *x* microseconds to ensure you do not go over the limit for calls with Freshsales.

By default the cycle is set to 500ms, with a buffer for safety of 100ms added on.

#### Enable Rate Limiting

Setup your API instance as normal, with an added:

```php
$api->enableRateLimiting();
```

This will turn on rate limiting with the default 500ms cycle and 100ms buffer. To change this, do the following:

```php
$api->enableRateLimiting(0.25 * 1000, 0);
```

This will set the cycle to 250ms and 0ms buffer.

#### Disabiling Rate Limiting

If you've previously enabled it, you simply need to run:

```php
$api->disableRateLimiting();
```

#### Checking Rate Limiting Status

```php
$api->isRateLimitingEnabled();
```

### Errors

This library internally catches only 400-500 status range errors through Guzzle. You're able to check for an error of this type and get its response status code and body.

```php
$call = $api->rest('GET', '/xxxx/non-existant-route-or-object');

if ($call->errors) {
  echo "Oops! {$call->status} error";
  var_dump($call->body);

  // Original exception can be accessed via `$call->exception`
  // Example, if response body was `{"error": "Not found"}`...
  /// then: `$call->body` would return "Not Found"
}
```

### Logging

This library accepts a PSR-compatible logger.

```php
$api->setLogger(... your logger instance ...);
```

## LICENSE

This project is released under the MIT [license](https://github.com/ianfortier/Basic-Freshsales-API/blob/master/LICENSE).
This project is largely inspired (aka copy <3 ) from the MIT [osiset/Basic-Shopify-API](https://github.com/osiset/Basic-Shopify-API/blob/master/LICENSE).