# Contributing to Laravel Relevance

Thank you for considering contributing to **Laravel Relevance**!

As this toolkit is in **active early development**, your feedback, bug reports, performance benchmarks, and pull requests are warmly welcomed and will directly shape the v1.0 release.

---

## Code of Conduct

To ensure an open and welcoming ecosystem, we expect all participants to maintain a professional, collaborative, and respectful tone in all discussions, issues, and pull requests.

---

## How Can You Contribute?

1. **Reporting Bugs**: Check existing issues first. If not found, open a new issue with a clear reproduction script, environment details (PHP version, Laravel version, database driver), and expected vs actual behavior.
2. **Suggesting Enhancements**: Propose new ideas, driver adapters, or algorithmic improvements in GitHub Discussions or Issues.
3. **Submitting Pull Requests**: Follow the guidelines below to ensure a smooth review process.

---

## Development Setup

1. Fork and clone the repository:
   ```bash
   git clone https://github.com/cyrus-48/laravel-relevance.git
   cd laravel-relevance
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Run the test suite:
   ```bash
   composer test
   # or
   vendor/bin/phpunit
   ```

4. Run static analysis:
   ```bash
   composer analyse
   # or
   vendor/bin/phpstan analyse
   ```

---

## Core Contribution Rules & Standards

To maintain high architectural integrity, all pull requests must meet the following non-negotiable standards:

### 1. Strict Typing & PHPStan Level 8 Clean
- Every PHP file **must** begin with `declare(strict_types=1);`.
- Use explicit argument, return, and property type declarations everywhere.
- When working with arrays, provide complete PHPDoc generics (e.g. `array<string, float>`, `list<Hit>`).
- Running `vendor/bin/phpstan analyse` must report **0 errors** at **Level 8**.

### 2. Immutability for DTOs
- Data Transfer Objects (DTOs) passed across pipeline stages should be declared `final readonly class` (e.g. `SearchRequest`, `Hit`, `ScoreBreakdown`).
- Use mutation helpers that return fresh instances (e.g. `$hit->with(...)`) rather than mutating state in-place.

### 3. Fail-Soft Architecture
- Optional pipeline stages (rerankers, external embedding providers, personalization) must **never** fail the entire search with an uncaught exception.
- Wrap external network calls with timeout budgets and catch blocks, degrading gracefully to candidate ranking and tagging `degraded: ['stage_name']`.

### 4. Comprehensive Test Coverage
- Bug fixes **must** include a failing test that reproduces the issue.
- New features **must** include corresponding Unit, Contract, or Feature tests in `tests/`.
- All tests must pass cleanly in SQLite in-memory without requiring external services for unit runs.

### 5. Git Commit Standards
We follow [Conventional Commits](https://www.conventionalcommits.org/):
- `feat:` for new capabilities or drivers
- `fix:` for bug fixes
- `test:` for adding or improving test coverage
- `docs:` for documentation updates
- `refactor:` for code cleanups without changing functionality

---

## Pull Request Checklist

Before submitting your PR, ensure:
- [ ] All tests pass: `vendor/bin/phpunit`
- [ ] Static analysis passes: `vendor/bin/phpstan analyse`
- [ ] No temporary `var_dump()`, `ray()`, or debugging code remains.
- [ ] Documentation or examples are updated where appropriate.
- [ ] Commit messages follow the conventional format.
