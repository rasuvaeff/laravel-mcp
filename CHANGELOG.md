# Changelog

## 0.1.0 — 2026-11-26

- Initial package: Laravel integration of `rasuvaeff/mcp` and `rasuvaeff/mcp-openapi`.
- Agent skill `rasuvaeff-laravel-mcp` in `resources/skills`.
- A PSR-18 client is bound when the application has none; `symfony/cache` is required for the PSR-6 pool.
- The cache the server counts with keeps the types of numbers: on a Redis store the call budget did not limit and notifications were lost.
- Requires `rasuvaeff/mcp` `^0.1.3`: the server rejects a call when the cache reports a failed write and counts a counter the store keeps as text.
- An unknown or empty `guard` fails with `Unknown MCP guard` instead of a `ValueError`.
- Documented: Swoole and RoadRunner checked; under RoadRunner a listen stream arrives when it ends.
- Requires `rasuvaeff/mcp` `^0.1.4`: a listen stream no longer ends the output buffer of Swoole or of the PSR-7 capture under RoadRunner.
