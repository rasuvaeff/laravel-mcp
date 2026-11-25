# Changelog

## Unreleased

- Initial package: Laravel integration of `rasuvaeff/mcp` and `rasuvaeff/mcp-openapi`.
- Agent skill `rasuvaeff-laravel-mcp` in `resources/skills`.
- A PSR-18 client is bound when the application has none; `symfony/cache` is required for the PSR-6 pool.
- The cache the server counts with keeps the types of numbers: on a Redis store the call budget did not limit and notifications were lost.
