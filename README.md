# Keycloak OIDC Demo

Demo del flusso **Authorization Code Flow** (OAuth 2.0 + OpenID Connect) con Keycloak e PHP.

---

## Prerequisiti

- [Docker Desktop](https://www.docker.com/products/docker-desktop/)
- Nessun altro requisito — tutto gira nei container

---

## Avvio rapido

```bash
# 1. Clona / entra nella directory
cd kc_farc

# 2. Avvia tutti i servizi
docker compose up -d --build

# 3. Installa le dipendenze PHP (solo al primo avvio o dopo un rebuild)
docker exec php_demo composer install --no-dev --working-dir=/var/www/html
```

### Servizi avviati

| Servizio | URL | Credenziali |
|---|---|---|
| **Demo PHP** | http://localhost:8081 | — |
| **Keycloak Admin** | http://localhost:8080/admin | `admin` / `admin` |
| **PostgreSQL** | `localhost:5432` | `keycloak` / `keycloak_secret` |

---

## Configurazione iniziale Keycloak (una tantum)

Esegui i comandi seguenti **nell'ordine indicato** dopo `docker compose up -d`:

```bash
# Autenticati come admin
docker exec keycloak /opt/keycloak/bin/kcadm.sh config credentials \
  --server http://localhost:8080 --realm master --user admin --password admin

# Disabilita SSL required su master (sviluppo locale)
docker exec keycloak /opt/keycloak/bin/kcadm.sh update realms/master -s sslRequired=none

# Crea il realm Fonarcom
docker exec keycloak /opt/keycloak/bin/kcadm.sh create realms \
  -s realm=Fonarcom -s enabled=true -s sslRequired=none

# Crea il client local-client-1
docker exec keycloak /opt/keycloak/bin/kcadm.sh create clients -r Fonarcom \
  -s clientId=local-client-1 \
  -s enabled=true \
  -s publicClient=true \
  -s 'redirectUris=["http://localhost:8081/callback.php","http://localhost:8081/*"]' \
  -s 'webOrigins=["http://localhost:8081"]' \
  -s 'attributes={"post.logout.redirect.uris":"http://localhost:8081/##http://localhost:8081/*"}'

# Crea un utente di test
docker exec keycloak /opt/keycloak/bin/kcadm.sh create users -r Fonarcom \
  -s username=testuser \
  -s enabled=true \
  -s email=test@fonarcom.it \
  -s firstName=Test \
  -s lastName=User

# Imposta la password (non temporanea)
docker exec keycloak /opt/keycloak/bin/kcadm.sh set-password -r Fonarcom \
  --username testuser --new-password password
```

---

## Struttura del progetto

```
kc_farc/
├── docker-compose.yml       # PostgreSQL + Keycloak + PHP/Apache
├── Dockerfile               # PHP 8.2 + Apache + Xdebug + Composer
├── xdebug.ini               # Configurazione Xdebug
├── .vscode/
│   └── launch.json          # Debug PHP in VS Code (F5)
└── app/
    ├── config.php            # Costanti: realm, client, URL Keycloak
    ├── jwks_cache.php        # Fetch + cache (1h) chiavi pubbliche JWKS
    ├── index.php             # Homepage con pulsante login
    ├── login.php             # Genera state, redirect a Keycloak
    ├── callback.php          # Valida state, scambia code→token, verifica JWT
    ├── dashboard.php         # Mostra dati utente dopo il login
    └── logout.php            # Distrugge sessione + redirect KC end_session
```

---

## Aggiungere nuove pagine protette

Ogni pagina che richiede autenticazione deve iniziare con:

```php
<?php
require_once 'auth.php';
$user = requireAuth();

// Da qui $user è garantito valido:
// $user['username'], $user['name'], $user['email'], $user['roles']
```

`requireAuth()` gestisce automaticamente:

| Situazione | Comportamento |
|---|---|
| Sessione assente | Redirect a `login.php` |
| Access token scaduto (default: 5 min) | Refresh silenzioso con Keycloak |
| Refresh token scaduto (default: 30 min) | Sessione distrutta + redirect a `login.php` |
| Refresh token revocato da KC | Sessione distrutta + redirect a `login.php` |

### Come funziona il refresh

`requireAuth()` viene chiamata in cima a ogni pagina protetta e segue questo flusso:

```
requireAuth() chiamata su ogni pagina protetta
       │
       ▼
 kc_user in sessione? ──No──→ redirect login.php
       │
      Sì
       │
       ▼
 time() >= kc_exp - 30? ──No──→ return $user  (token ancora valido)
       │
      Sì  (scaduto o mancano < 30 sec)
       │
       ▼
 POST /token  grant_type=refresh_token  ← una sola chiamata a KC
       │
  risposta ok? ──No──→ session_destroy() → redirect login.php
       │
      Sì
       │
       ▼
 _storeSession() → nuovi access_token + refresh_token salvati in sessione
       │
       ▼
 return $user
```

**Quante volte viene usato il refresh token?**

Il refresh token viene usato **una volta per ogni scadenza dell'access token**. Ogni volta che viene usato, KC emette una nuova coppia e quello precedente viene invalidato immediatamente (rotazione obbligatoria):

```
login
  → access_token_1 (scade in 5 min) + refresh_token_1 (scade in 30 min)

dopo 5 min → refresh_token_1 usato (1 volta) → invalidato
  → access_token_2 + refresh_token_2

dopo altri 5 min → refresh_token_2 usato (1 volta) → invalidato
  → access_token_3 + refresh_token_3

...

dopo 30 min totali → refresh_token scaduto → KC risponde invalid_grant
  → session_destroy() → redirect login.php  (nuovo login richiesto)
```

> **Attenzione:** se un vecchio refresh token già ruotato viene riutilizzato (es. due tab aperte in race condition), KC lo interpreta come potenziale furto di token e invalida **tutta la sessione SSO**.

Note importanti:
- **Il refresh token viene ruotato**: KC ne emette uno nuovo ad ogni refresh, quello vecchio viene invalidato immediatamente.
- **Se il refresh token è scaduto**: KC risponde `invalid_grant` → l'utente deve rifare il login.
- **Se l'admin revoca la sessione** dalla console KC (o l'utente fa logout da un altro dispositivo): KC risponde `invalid_grant` → stesso comportamento.
- **Il logout da KC non è immediato nell'app**: l'app si accorge della revoca solo al successivo tentativo di refresh (allo scadere dell'access token). Per notifica immediata serve implementare il Back-Channel Logout.

---

## Debug con VS Code

1. Installa l'estensione **PHP Debug** (`xdebug.php-debug`)
2. Premi `F5` → seleziona **PHP Debug (Docker)**
3. Metti un breakpoint in un file PHP in `app/`
4. Apri http://localhost:8081 nel browser

---

## Fermare i servizi

```bash
docker compose down
```

Per cancellare anche il volume del database:

```bash
docker compose down -v
```
