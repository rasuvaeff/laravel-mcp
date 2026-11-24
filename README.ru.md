# rasuvaeff/laravel-mcp

[![Stable Version](https://img.shields.io/packagist/v/rasuvaeff/laravel-mcp?label=stable&sort_semver=1)](https://packagist.org/packages/rasuvaeff/laravel-mcp)
[![Total Downloads](https://img.shields.io/packagist/dt/rasuvaeff/laravel-mcp)](https://packagist.org/packages/rasuvaeff/laravel-mcp)
[![Build](https://img.shields.io/github/actions/workflow/status/rasuvaeff/laravel-mcp/build.yml?branch=master)](https://github.com/rasuvaeff/laravel-mcp/actions)
[![Static analysis](https://img.shields.io/github/actions/workflow/status/rasuvaeff/laravel-mcp/static-analysis.yml?branch=master&label=static%20analysis)](https://github.com/rasuvaeff/laravel-mcp/actions)
[![Psalm level](https://img.shields.io/badge/psalm-level%201-141F48?logo=psalm&logoColor=white)](https://github.com/rasuvaeff/laravel-mcp/blob/master/psalm.xml)
[![License](https://img.shields.io/packagist/l/rasuvaeff/laravel-mcp)](LICENSE.md)

[English version](README.md)

Интеграция [`rasuvaeff/mcp`](https://github.com/rasuvaeff/mcp) с Laravel: сервер Model Context
Protocol поверх официального [`mcp/sdk`](https://packagist.org/packages/mcp/sdk). Инструменты из
контейнера, HTTP-эндпоинт, консольные команды и
[мост OpenAPI](https://github.com/rasuvaeff/mcp-openapi).

[`laravel/mcp`](https://github.com/laravel/mcp) — официальный пакет со своей реализацией протокола, генераторами и клиентом. Берите его, если не нужно то, ради чего сделан этот: один и тот же код сервера в нескольких фреймворках, мост OpenAPI, интерсепторы и видимость из `rasuvaeff/mcp`.

> **Пользуетесь ИИ-ассистентом?** [llms.txt](llms.txt) — компактный справочник по API
> для модели. Участникам: см. [AGENTS.md](AGENTS.md). В проектах с Composer-плагином
> [llm/skills](https://github.com/roxblnfk/skills) скилл пакета сам появляется в
> `.agents/skills/` при установке.

## Требования

| Требование | Версия |
|---|---|
| PHP | 8.3 – 8.6 |
| `laravel/framework` | `^12.41 \|\| ^13.0` |
| `rasuvaeff/mcp`, `rasuvaeff/mcp-openapi` | `^0.1` |
| `firebase/php-jwt` | необязательно, `^7.0`: проверка JWT для OAuth |

## Установка

```bash
composer require rasuvaeff/laravel-mcp
php artisan vendor:publish --tag=rasuvaeff-mcp
```

## Использование

```php
use Mcp\Server\Capability\Attribute\McpTool;

final readonly class OrderTools
{
    public function __construct(private OrderRepository $orders) {}

    #[McpTool(name: 'order.status')]
    public function status(string $orderId): string
    {
        return $this->orders->status($orderId);
    }
}
```

```php
// config/rasuvaeff-mcp.php
'tools' => [App\Mcp\OrderTools::class],
'endpoint_secret' => env('MCP_SECRET', ''),
'allowed_hosts' => ['mcp.example.com'],
```

Эндпоинт — `/mcp`. Без секрета он на всё отвечает 503.

```bash
php artisan mcp:list    # what is served
php artisan mcp:serve   # stdio, for a local agent
```

### Конфигурация

`route.path` — адрес эндпоинта (`mcp`, `''` не регистрирует маршрут), `route.middleware`
выполняется перед проверкой доступа. `guard` — `secret` или `oauth`. Остальные ключи те же,
что в `rasuvaeff/yii3-mcp`; каждый описан в опубликованном файле.

`ProtectedResourceMetadataHandler` отдайте своим маршрутом по адресу
`/.well-known/oauth-protected-resource/<path>`.

### Долгоживущие воркеры

- Маршрут вне группы `web`: ни сессии в cookie, ни CSRF-токена.
- Сервер — `scoped`: под Octane собирается на запрос и уходит вместе с ним. Сессии на диске и шина уведомлений остаются.
- `config:cache` работает: в конфиге нет замыканий, `apps.definitions[].html` — строка.

### Компоненты

| Класс | Роль |
|---|---|
| `McpServiceProvider` | привязки, конфиг, маршрут и команды |
| `HttpEndpoint` | действие маршрута: проверка доступа, затем эндпоинт MCP |
| `Guard` | `secret` или `oauth` |

## Безопасность

- Эндпоинт только для доверенных клиентов. Без `endpoint_secret`, `client_secrets` или `oauth` каждый запрос отклоняется.
- Укажите в `allowed_hosts` публичное имя: это защита от DNS rebinding, без неё разрешён только localhost.
- Нескольким клиентам нужны `client_secrets`: сессия принадлежит клиенту, который её открыл.
- Сбой внутри инструмента пишется в лог, его текст клиент не получает.

## Примеры

См. [examples/](examples/): скрипт работает без сети.

## Разработка

PHP и Composer на хосте не нужны, всё в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
```

Или через Make: `make build`, `make cs-fix`, `make psalm`, `make test`.

## Лицензия

BSD-3-Clause. См. [LICENSE.md](LICENSE.md).
