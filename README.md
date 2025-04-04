<p align="center">
  <img src="https://cdn.evntaly.com/Resources/og.png" alt="Evntaly Cover" width="100%">
</p>

<h1 align="center">Evntaly</h1>

<p align="center">
  An advanced event tracking and analytics platform designed to help developers capture, analyze, and react to user interactions efficiently.
  The full documentation can be found at [Evntaly Documentation](https://evntaly.gitbook.io/evntaly)
</p>

[![Latest Version on Packagist](https://img.shields.io/packagist/v/evntaly/evntaly-php.svg?style=flat-square)](https://packagist.org/packages/evntaly/evntaly-php)
[![MIT Licensed](https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square)](LICENSE.md)
[![Total Downloads](https://img.shields.io/packagist/dt/evntaly/evntaly-php.svg?style=flat-square)](https://packagist.org/packages/evntaly/evntaly-php)

# evntaly-php

**evntaly-php** is a PHP client for interacting with the Evntaly event tracking platform. It provides developers with a straightforward interface to initialize the SDK, track events, identify users, manage tracking states, and potentially interact with other Evntaly API features within PHP applications.

## Features

- **Initialize** the SDK with a developer secret and project token.
- **Track events** with comprehensive metadata and tags.
- **Identify users** for personalization and detailed analytics.
- **Enable or disable** tracking globally within your application instance.

## Installation

Install the SDK using [Composer](https://getcomposer.org/):

```bash
composer require evntaly/evntaly-php
```

## Usage

### Initialization

First, include the Composer autoloader in your project. Then, initialize the SDK with your developer secret and project token obtained from your Evntaly dashboard.

```php
use Evntaly\EvntalySDK;

$developerSecret = 'YOUR_DEVELOPER_SECRET';
$projectToken = 'YOUR_PROJECT_TOKEN';

$sdk = new EvntalySDK($developerSecret, $projectToken);
```

---

### Tracking Events

To track an event, use the `track` method with an associative array containing the event details.

```php
$response = $sdk->track([
    "title" => "Payment Received",
    "description" => "User completed a purchase successfully",
    "message" => "Order #12345 confirmed for user.",
    "data" => [
        "user_id" => "usr_67890",
        "order_id" => "12345",
        "amount" => 99.99,
        "currency" => "USD",
        "payment_method" => "credit_card",
        "timestamp" => date('c'),
        "referrer" => "social_media",
        "email_verified" => true
    ],
    "tags" => ["purchase", "payment", "usd", "checkout-v2"],
    "notify" => true,
    "icon" => "💰",
    "apply_rule_only" => false,
    "user" => ["id" => "usr_0f6934fd-99c0-41ca-84f4"],
    "type" => "Transaction",
    "sessionID" => "sid_20750ebc-dabf-4fd4-9498",
    "feature" => "Checkout",
    "topic" => "@Sales"
]);
```

---

### Identifying Users

To identify or update user details, use the `identifyUser` method. This helps link events to specific users and enriches your analytics.

```php
$response = $sdk->identifyUser([
    "id" => "usr_0f6934fd-99c0-41ca-84f4",
    "email" => "john.doe@example.com",
    "full_name" => "Johnathan Doe",
    "organization" => "ExampleCorp Inc.",
    "data" => [
        "username" => "JohnD",
        "location" => "New York, USA",
        "plan_type" => "Premium",
        "signup_date" => "2024-01-15T10:00:00Z",
        "timezone" => "America/New_York"
    ]
]);

```

---

### Enabling/Disabling Tracking

You can globally enable or disable event tracking for the current SDK instance. This might be useful for development/testing or respecting user consent.

```php
// Disable tracking - subsequent track/identify calls will be ignored
$sdk->disableTracking();

// Re-enable tracking
$sdk->enableTracking();
```

---

### License

This project is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

---

**Note:** Always replace `'YOUR_DEVELOPER_SECRET'` and `'YOUR_PROJECT_TOKEN'` with your actual credentials provided by Evntaly. Keep your Developer Secret confidential.
