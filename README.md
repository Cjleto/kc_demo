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

Al primo avvio il realm `Fonarcom` e il client `local-client-1` devono già esistere (configurati manualmente o da export).  
Se parti da zero, esegui i comandi seguenti dopo `docker compose up -d`:

```bash
# Autenticati come admin
docker exec keycloak /opt/keycloak/bin/kcadm.sh config credentials \
  --server http://localhost:8080 --realm master --user admin --password admin

# Disabilita SSL required (sviluppo locale)
docker exec keycloak /opt/keycloak/bin/kcadm.sh update realms/master -s sslRequired=none
docker exec keycloak /opt/keycloak/bin/kcadm.sh update realms/Fonarcom -s sslRequired=none

# Aggiungi redirect URI al client
docker exec keycloak /opt/keycloak/bin/kcadm.sh update clients/<CLIENT_UUID> \
  -r Fonarcom \
  -s 'redirectUris=["http://localhost:8081/callback.php","/*"]' \
  -s 'attributes={"post.logout.redirect.uris":"http://localhost:8081/##http://localhost:8081/*"}'
```

> Per trovare il `CLIENT_UUID`:
> ```bash
> docker exec keycloak /opt/keycloak/bin/kcadm.sh get clients -r Fonarcom \
>   --query clientId=local-client-1 --fields id
> ```

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
