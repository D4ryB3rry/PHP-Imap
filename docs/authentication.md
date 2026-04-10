# Authentication

Three credential types are built in. Pass the one you need to `Config::$credential`.

## Plain / Login

```php
use D4ry\ImapClient\Auth\PlainCredential;
use D4ry\ImapClient\Auth\LoginCredential;

// AUTHENTICATE PLAIN (preferred)
$credential = new PlainCredential('user@example.com', 'password');

// LOGIN command (legacy fallback)
$credential = new LoginCredential('user@example.com', 'password');
```

`PlainCredential` uses `AUTHENTICATE PLAIN` with SASL-IR when the server advertises it. Use `LoginCredential` only for servers that reject `AUTHENTICATE`.

## OAuth2 (Google, Microsoft, etc.)

```php
use D4ry\ImapClient\Auth\XOAuth2Credential;

$credential = new XOAuth2Credential(
    email: 'user@gmail.com',
    accessToken: 'ya29.a0AfH6SM...',
);
```

### Automatic Token Refresh

If your token may expire during a long-running session, supply a refresher. The library calls it transparently when the server rejects the current token and retries the AUTHENTICATE exchange.

```php
use D4ry\ImapClient\Auth\Contract\TokenRefresherInterface;
use D4ry\ImapClient\Auth\XOAuth2Credential;

class MyTokenRefresher implements TokenRefresherInterface
{
    public function refresh(string $currentToken): string
    {
        // Call your OAuth provider to get a new access token
        return $newAccessToken;
    }
}

$credential = new XOAuth2Credential(
    email: 'user@gmail.com',
    accessToken: $currentToken,
    tokenRefresher: new MyTokenRefresher(),
);
```

## See also

- [Configuration](configuration.md)
- [Error Handling](error-handling.md) — catching `AuthenticationException`
