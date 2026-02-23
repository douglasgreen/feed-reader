---
name: AGENTS.md
description: Guidelines for AI coding assistants.
version: 1.0.0
modified: 2026-02-22
---

# AGENTS.md

## 🤖 Instructions for AI Assistants

This document defines the engineering standards, architectural patterns, and quality requirements for this project. **You must follow these instructions for every code generation task.**

---

### 1. Core Technical Stack

- **Language:** PHP 8.3+ (Strictly enforced)
- **Dependency Management:** Composer 2.6+
- **Static Analysis:** PHPStan Level 8
- **Testing:** PHPUnit 10+ (using Attributes)
- **Coding Style:** PSR-12 + Strict Rules (via PHP CS Fixer)

---

### 2. Mandatory Coding Patterns

#### 2.1 Typing & Classes

- **Strict Typing:** All function parameters and return values must have explicit types. Use `mixed` only as a last resort.
- **Modern PHP:** Use PHP 8.3 features where applicable (e.g., `readonly` classes, constructor property promotion, DNF types).
- **Immutability:** Default to `final readonly class` unless inheritance or mutation is explicitly required.

#### 2.2 Dependency Injection

- Prefer **Constructor Injection**.
- Prefer interfaces for dependencies to facilitate mocking in tests.

---

### 3. Project Structure Reference

Follow the established directory layout:

- `src/`: Core logic (Namespace `App\` or `Vendor\LibraryName\`)
- `tests/Unit/`: Isolated logic tests (Fast, no IO)
- `tests/Integration/`: Database/Service boundary tests
- `bin/`: CLI entry points
- `public/`: Web entry points

---

### 4. Quality Assurance Workflow

Before suggesting a solution is "complete," ensure it passes the project's QA suite. If you have terminal access, run:

```bash
# Check code style, static analysis, and tests
composer qa
```

**Verification Checklist:**

1. **PHPStan:** Must pass at Level 9 without ignored errors.
2. **PHP CS Fixer:** Must adhere to the rules in `.php-cs-fixer.dist.php`.
3. **PHPUnit:** New features must include tests using PHPUnit 10 Attributes (e.g., `#[CoversClass]`, `#[Test]`).

---

### 5. Standard Implementation Examples

#### When creating a Class:

```php
final readonly class MyService
{
    public function __construct(
        private DependencyInterface $dependency,
    ) {}

    public function execute(int $id): string
    {
        // Implementation
    }
}
```

#### When creating a Test:

```php
#[CoversClass(MyService::class)]
#[Small]
final class MyServiceTest extends TestCase
{
    // Use attributes, not annotations
}
```

---

### 6. Security & Secrets

- **Never** generate hardcoded passwords, API keys, or secrets.
- Use environment variable placeholders (e.g., `getenv('API_KEY')`).
- If modifying `.env` files, only touch `.env.example`. **Never commit or suggest code that modifies `.env.local`.**

---

### 7. Documentation

- Follow the **Diátaxis** framework.
- If adding a major feature, suggest an update to `docs/architecture.md` or create a new ADR (Architecture Decision Record) in `docs/adr/`.
