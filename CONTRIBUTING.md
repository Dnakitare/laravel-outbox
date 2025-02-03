# Contributing to Laravel Outbox

First off, thank you for considering contributing to Laravel Outbox! It's people like you that make Laravel Outbox such a great tool.

## Table of Contents

- [Code of Conduct](#code-of-conduct)
- [Getting Started](#getting-started)
- [Development Workflow](#development-workflow)
- [Testing Guidelines](#testing-guidelines)
- [Code Standards](#code-standards)
- [Documentation](#documentation)
- [Pull Request Process](#pull-request-process)
- [Release Process](#release-process)

## Code of Conduct

This project and everyone participating in it is governed by our [Code of Conduct](CODE_OF_CONDUCT.md). By participating, you are expected to uphold this code.

## Getting Started

### Prerequisites

- PHP 8.1 or higher
- Composer
- SQLite (for testing)

### Local Development Environment

1. Fork the repository
2. Clone your fork:
   ```bash
   git clone https://github.com/YOUR_USERNAME/laravel-outbox.git
   cd laravel-outbox
   ```

3. Install dependencies:
   ```bash
   composer install
   ```

4. Create development environment:
   ```bash
   composer require --dev orchestra/workbench
   vendor/bin/testbench workbench:create
   ```

## Development Workflow

1. Create a new branch:
   ```bash
   git checkout -b feature/my-awesome-feature
   ```

2. Make your changes

3. Run tests:
   ```bash
   composer test
   ```

4. Format code:
   ```bash
   composer format
   ```

5. Commit your changes:
   ```bash
   git commit -m "feat: add awesome feature"
   ```

   We follow [Conventional Commits](https://www.conventionalcommits.org/):
   - `feat:` for new features
   - `fix:` for bug fixes
   - `docs:` for documentation changes
   - `style:` for code style changes
   - `refactor:` for code refactoring
   - `test:` for adding tests
   - `chore:` for routine tasks

## Testing Guidelines

### Test Structure

Tests are organized into three categories:

1. **Unit Tests** (`tests/Unit/`)
   - Test individual components in isolation
   - Mock dependencies
   - Fast execution

2. **Integration Tests** (`tests/Integration/`)
   - Test component interactions
   - Use test databases
   - Test database operations

3. **Feature Tests** (`tests/Feature/`)
   - Test full features
   - End-to-end testing
   - Test from user perspective

### Writing Tests

1. **Naming Convention**
   ```php
   public function test_it_does_something(): void
   {
       // Arrange
       // Act
       // Assert
   }
   ```

2. **Test Data**
   - Use factories when possible
   - Clear, meaningful test data
   - Document data requirements

3. **Assertions**
   - One concept per test
   - Clear failure messages
   - Use appropriate assertions

Example:
```php
public function test_it_stores_event_in_outbox(): void
{
    // Arrange
    $event = new TestEvent('test');
    
    // Act
    $this->outbox->transaction('Test', '123', function () use ($event) {
        event($event);
    });
    
    // Assert
    $this->assertDatabaseHas('outbox_messages', [
        'type' => 'event',
        'status' => 'pending',
    ]);
}
```

## Code Standards

We follow the [PSR-12](https://www.php-fig.org/psr/psr-12/) coding standard and the [PSR-4](https://www.php-fig.org/psr/psr-4/) autoloading standard.

### General Rules

1. **Class Organization**
   ```php
   class OutboxService
   {
       // Constants first
       private const MAX_ATTEMPTS = 3;
       
       // Properties next
       private $repository;
       
       // Constructor after properties
       public function __construct(Repository $repository)
       {
           $this->repository = $repository;
       }
       
       // Public methods
       public function doSomething(): void
       {
       }
       
       // Protected methods
       protected function helperMethod(): void
       {
       }
       
       // Private methods last
       private function internalHelper(): void
       {
       }
   }
   ```

2. **Method Organization**
   - Keep methods small and focused
   - Use descriptive names
   - Type hint everything
   - Document complex logic

3. **Documentation**
   - PHPDoc blocks for classes and methods
   - Inline comments for complex logic
   - Clear parameter and return type hints

### Laravel Best Practices

1. **Database**
   - Use migrations
   - Define relationships
   - Use Eloquent when possible

2. **Configuration**
   - Use config files
   - Environment variables for sensitive data
   - Sensible defaults

3. **Services**
   - Single responsibility
   - Dependency injection
   - Interface segregation

## Documentation

### README Updates

When adding features, update:
1. Feature list
2. Installation instructions (if changed)
3. Usage examples
4. Configuration options

### PHPDoc Blocks

```php
/**
 * Process a batch of outbox messages.
 *
 * @param  int  $batchSize  Number of messages to process
 * @param  bool  $loop  Whether to continue processing in a loop
 * @throws \OutboxException When processing fails
 * @return int Number of processed messages
 */
public function processBatch(int $batchSize, bool $loop = false): int
{
    // Implementation
}
```

### Inline Comments

```php
// Calculate retry delay using exponential backoff
$delay = min(
    static::MAX_DELAY,
    static::BASE_DELAY * pow(2, $attempts)
);
```

## Pull Request Process

1. Update relevant documentation
2. Add/update tests
3. Run test suite
4. Format code
5. Create pull request
6. Wait for review
7. Address feedback
8. Merge after approval

### PR Template

```markdown
## Description
Brief description of your changes

## Type of Change
- [ ] Bug fix
- [ ] New feature
- [ ] Breaking change
- [ ] Documentation update

## How Has This Been Tested?
Describe your test process

## Checklist
- [ ] Tests added/updated
- [ ] Documentation updated
- [ ] Code formatted
- [ ] Dependencies updated
```

## Release Process

For maintainers:

1. Update version in `composer.json`
2. Update `CHANGELOG.md`:
   ```markdown
   ## [1.1.0] - 2024-02-02
   ### Added
   - New feature X
   ### Changed
   - Updated Y
   ### Fixed
   - Bug Z
   ```
3. Create release PR
4. Merge after approval
5. Tag release:
   ```bash
   git tag v1.1.0
   git push origin v1.1.0
   ```
6. Create GitHub release
7. Update documentation