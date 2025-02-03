# Laravel Outbox Pattern

A robust implementation of the Transactional Outbox Pattern for Laravel applications. This package helps ensure reliable message delivery in distributed systems by storing events and jobs in a database before processing them.

## 📋 Table of Contents

- [Features](#features)
- [Installation](#installation)
- [Basic Usage](#basic-usage)
- [Configuration](#configuration)
- [Processing Messages](#processing-messages)
- [Maintenance](#maintenance)
- [Debugging & Monitoring](#debugging--monitoring)
- [Health Checks](#health-checks)
- [Testing](#testing)
- [Contributing](#contributing)
- [Security](#security)
- [Credits](#credits)
- [License](#license)

## ✨ Features

- 🔒 **Transactional Integrity**: Events and jobs are stored alongside your database changes
- 🔄 **Reliable Processing**: Batch processing with configurable sizes and automatic retries
- 📬 **Dead Letter Queue**: Automatic handling of failed messages
- 📊 **Monitoring**: Comprehensive monitoring and debugging tools
- 🏥 **Health Checks**: Built-in health checks and statistics
- 🛠️ **Maintenance Tools**: Console commands for managing messages
- ⚙️ **Configurable**: Extensive configuration options
- 🧪 **Testing Utilities**: Helpers for testing your outbox implementation

## 🚀 Installation

You can install the package via composer:

```bash
composer require laravel/outbox
```

After installing, publish and run the migrations:

```bash
php artisan vendor:publish --provider="Laravel\Outbox\OutboxServiceProvider"
php artisan migrate
```

## 📝 Basic Usage

Use the Outbox facade to wrap your database transactions:

```php
use Laravel\Outbox\Facades\Outbox;

Outbox::transaction('Order', $orderId, function () use ($order) {
    // Your database operations
    $order->save();
    
    // Events and jobs will be stored in the outbox
    event(new OrderCreated($order));
    ProcessOrder::dispatch($order);
});
```

## ⚙️ Configuration

Configure the package in `config/outbox.php`:

```php
return [
    'table' => [
        'messages' => 'outbox_messages',
        'dead_letter' => 'outbox_dead_letter',
    ],
    
    'processing' => [
        'max_attempts' => 3,
        'batch_size' => 100,
        'process_immediately' => true,
        'process_delay' => 1,
    ],
    
    'dead_letter' => [
        'enabled' => true,
        'retention_days' => 30,
    ],
];
```

## 🔄 Processing Messages

Process messages using the provided artisan commands:

```bash
# Process pending messages
php artisan outbox:process

# Process in a continuous loop with custom batch size
php artisan outbox:process --batch=50 --loop

# Process with sleep between batches
php artisan outbox:process --sleep=5 --loop
```

## 🛠️ Maintenance

Manage your outbox with these commands:

```bash
# Prune old messages
php artisan outbox:prune --days=7

# Retry failed messages
php artisan outbox:retry --all
php artisan outbox:retry --id=<message-id>

# Inspect dead letter queue
php artisan outbox:inspect-dead-letter
php artisan outbox:inspect-dead-letter --id=<message-id>
```

## 🔍 Debugging & Monitoring

Use the debugger for detailed insights:

```php
use Laravel\Outbox\Debug\OutboxDebugger;

$debugger = app(OutboxDebugger::class);

// Inspect specific message
$details = $debugger->inspectMessage($messageId);

// Find problematic messages
$issues = $debugger->findProblematicMessages();

// Analyze patterns
$analysis = $debugger->analyzePatterns();
```

## 🏥 Health Checks

Monitor the health of your outbox:

```php
$health = Outbox::health();

// Returns:
[
    'status' => 'healthy|warning|critical',
    'messages' => [
        'pending' => 10,
        'processing' => 2,
        'completed' => 1000,
        'failed' => 5,
    ],
    'oldest_pending' => '2024-02-01 12:00:00',
    'stuck_processing' => 0,
]
```

Get detailed statistics:

```php
$stats = Outbox::getStats();
```

## 🧪 Testing

```bash
composer test
```

## 🤝 Contributing

Please see [CONTRIBUTING](CONTRIBUTING.md) for details.

## 🔒 Security

If you discover any security-related issues, please email security@example.com instead of using the issue tracker.

## 👥 Credits

- [Your Name](https://github.com/yourusername)
- [All Contributors](../../contributors)

## 📄 License

The MIT License (MIT). Please see [License File](LICENSE.md) for more information.