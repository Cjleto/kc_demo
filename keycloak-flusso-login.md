# Keycloak — Flusso di login con Authorization Code Flow

> Documento tecnico per il team. Descrive come funziona il login delegato a Keycloak,
> i meccanismi di sicurezza coinvolti e come verificare l'autenticità dei token ricevuti.

---

## Contesto

Nella nuova architettura, la nostra applicazione **non gestisce più direttamente** username e password degli utenti. Delega l'intera fase di autenticazione a **Keycloak**, un Identity Provider open source che implementa i protocolli standard OAuth 2.0 e OpenID Connect (OIDC).

Il flusso utilizzato si chiama **Authorization Code Flow** ed è lo standard raccomandato per applicazioni web server-side.

---

## Il flusso passo per passo

### 1. L'utente clicca "Accedi"

La nostra app non mostra più un form con username e password. Al click su "Accedi" la app esegue due operazioni prima di fare qualsiasi redirect:

- genera un codice casuale chiamato **state** (es. `a3f9bc12de456ef7`)
- salva lo state in sessione PHP

Poi redirige il browser dell'utente verso la pagina di login di Keycloak, con questo URL:

```
http://keycloak-host/realms/Fonarcom/protocol/openid-connect/auth
    ?client_id=local-client-1
    &redirect_uri=https://mia-app.test/callback.php
    &response_type=code
    &scope=openid profile email roles
    &state=a3f9bc12de456ef7          ← il codice generato dalla nostra app
```

---

### 2. L'utente si autentica su Keycloak

Il browser carica la **pagina di login di Keycloak**. È qui che l'utente inserisce le sue credenziali — la nostra applicazione non le vede mai.

Keycloak verifica username e password nel suo database interno.

---

### 3. Keycloak genera il `code` e redirige

Se le credenziali sono corrette, Keycloak esegue queste operazioni internamente:

- genera un **code** casuale e monouso (es. `eyJhbGciOi...`)
- lo salva nel suo database locale associandolo a:
  - l'utente che si è appena autenticato
  - il client che ha fatto la richiesta (`local-client-1`)
  - il realm (`Fonarcom`)
  - gli scope richiesti
  - una scadenza di **60 secondi**
- redirige il browser dell'utente verso il `redirect_uri` della nostra app, passando il code e lo state nell'URL:

```
https://mia-app.test/callback.php
    ?code=eyJhbGciOi...
    &state=a3f9bc12de456ef7
```

> Il `code` da solo non vale nulla — è solo un bigliettino temporaneo. Per ottenere il token reale serve anche il `client_secret`, che non passa mai per il browser.

---

### 4. La nostra app verifica lo `state` (protezione CSRF)

Quando il browser arriva su `/callback.php`, la prima cosa che facciamo è confrontare lo state ricevuto da Keycloak con quello salvato in sessione al passo 1:

```
state ricevuto da KC:  a3f9bc12de456ef7
state salvato in sessione: a3f9bc12de456ef7
                                ↓
                           ✅ coincidono → procedi
                           ❌ diversi   → blocca tutto
```

**Perché è importante questo controllo?**

Senza di esso, un attaccante potrebbe costruire a mano un URL di callback con un `code` valido (ottenuto avviando lui stesso un login) e forzare il nostro browser a completare il flusso — loggandoci con il suo account. Questo attacco si chiama **login CSRF**.

Lo state lo previene perché è generato dalla nostra app e salvato in sessione: nessun attaccante può conoscerlo in anticipo.

---

### 5. La nostra app scambia il `code` con i token

Verificato lo state, la nostra app esegue una chiamata **server-to-server** verso Keycloak — il browser non è coinvolto:

```
POST /realms/Fonarcom/protocol/openid-connect/token

grant_type=authorization_code
code=eyJhbGciOi...          ← il code ricevuto nel callback
client_id=local-client-1
client_secret=il-nostro-secret   ← dimostra che siamo noi il client legittimo
redirect_uri=https://mia-app.test/callback.php
```

Keycloak alla ricezione di questa richiesta:

1. verifica che il `code` esista nel suo database
2. verifica che non sia scaduto (> 60 secondi → errore)
3. verifica che `client_id` e `client_secret` corrispondano al client che ha avviato il flusso
4. verifica che il `redirect_uri` sia identico a quello della richiesta iniziale
5. **cancella il `code` dal suo database** — non può essere riusato
6. genera e restituisce i token JWT

```json
{
  "access_token":  "eyJhbGci...",
  "refresh_token": "eyJhbGci...",
  "id_token":      "eyJhbGci...",
  "expires_in":    300
}
```

> Il fatto che il code venga eliminato subito da KC è fondamentale: anche se qualcuno intercettasse l'URL del callback e leggesse il code, non potrebbe usarlo perché è già stato consumato dalla nostra chiamata.

---

### 6. Verifica della firma del JWT

Ricevuto l'`access_token`, **non basta decodificarlo** — bisogna verificare che sia autentico.

Un JWT è composto da tre parti separate da `.`:

```
header . payload . firma
```

Le prime due sono solo base64 — chiunque può leggerle. La **firma** invece è generata da Keycloak con la sua **chiave privata RSA**, che solo KC possiede.

Noi abbiamo la **chiave pubblica** di KC — e con quella possiamo verificare che:

- il token è stato firmato da KC (e non costruito da un attaccante)
- il payload non è stato modificato dopo la firma
- il token non è scaduto (`exp`)
- il token è emesso dal realm corretto (`iss`)

**Come ottenere la chiave pubblica di Keycloak**

KC espone le sue chiavi pubbliche su un endpoint standard chiamato **JWKS** (JSON Web Key Set):

```
GET http://keycloak-host/realms/Fonarcom/protocol/openid-connect/certs
```

Risposta:

```json
{
  "keys": [{
    "kid": "abc123",
    "kty": "RSA",
    "use": "sig",
    "n": "chiave-pubblica-in-base64...",
    "e": "AQAB"
  }]
}
```

Questa chiave pubblica va **cachata** in locale (es. 1 ora) — non ha senso richiederla ad ogni login. Cambia solo se KC ruota le chiavi, evento raro e pianificato.

In PHP la verifica avviene con la libreria `firebase/php-jwt`:

```php
use Firebase\JWT\JWT;
use Firebase\JWT\JWK;

$jwks    = /* lettura dalla cache o da KC */;
$payload = JWT::decode($accessToken, JWK::parseKeySet($jwks));
// Se arrivi qui, il token è autentico e verificato.
// Se il token è falso o manomesso, viene lanciata un'eccezione.
```

---

## Le protezioni in sintesi

| Protezione | Cosa garantisce | Dove avviene |
|---|---|---|
| **state** | Sei tu ad aver avviato il login (anti-CSRF) | Callback — confronto con sessione |
| **client_secret** | Solo la nostra app può scambiare il code | Chiamata POST /token |
| **code monouso** | Il code non può essere riusato dopo lo scambio | KC lo cancella dopo il primo uso |
| **verifica firma JWT** | Il token è autentico, emesso da KC, non alterato | Callback — dopo lo scambio |

Ogni protezione copre un vettore di attacco diverso — tutte e quattro sono necessarie.

---

## Schema del flusso completo

```
BROWSER              NOSTRA APP              KEYCLOAK
   |                      |                      |
   | clicca Accedi        |                      |
   |-------------------→  |                      |
   |                      | genera state         |
   |                      | salva in sessione    |
   |  redirect a KC       |                      |
   | ←-------------------  |                      |
   |                                             |
   |——— GET /auth?client_id&redirect_uri&state ——→|
   |                                             | mostra pagina login
   |         utente inserisce credenziali        |
   |                                             | verifica credenziali
   |                                             | genera code
   |                                             | salva code nel DB (60s)
   | ←—— redirect /callback?code=xxx&state=yyy ——|
   |                      |                      |
   | GET /callback        |                      |
   |-------------------→  |                      |
   |                      | verifica state ✅    |
   |                      |                      |
   |                      |— POST /token ————————→|
   |                      |   + code             | verifica code
   |                      |   + client_secret    | verifica secret
   |                      |                      | cancella code dal DB
   |                      | ←— access_token ——————|
   |                      |                      |
   |                      | scarica chiave       |
   |                      | pubblica da /certs   |
   |                      | verifica firma JWT ✅|
   |                      | salva in sessione    |
   |  redirect /dashboard |                      |
   | ←-------------------  |                      |
```

---

# Keycloak — Modifiche utente in corso di sessione

## Il problema

Il token JWT che hai in sessione è **statico** — viene generato al momento del login e non si aggiorna da solo.

```
Utente fa login → token generato con roles: ["customer"]

Admin su KC cambia l'utente:
  - aggiunge ruolo "admin"
  - oppure disabilita l'account
  - oppure cambia l'email

Il token in sessione dice ancora roles: ["customer"] ❌
La tua app non sa nulla delle modifiche
```

---

## Le soluzioni possibili

### Soluzione 1 — Accetti il ritardo _(più comune)_

Il token ha una scadenza breve (default KC: 5 minuti). Quando scade, il refresh token ne genera uno nuovo con i dati aggiornati da KC.

```
Modifica su KC → entro max 5 minuti il nuovo token rifletterà i cambiamenti
```

Per la maggior parte dei casi è accettabile. I cambi di ruolo non sono operazioni urgenti al secondo.

---

### Soluzione 2 — Riduci la durata del token

In KC puoi abbassare l'`Access Token Lifespan` a 1-2 minuti:

```
Admin Console → Realm Settings → Tokens → Access Token Lifespan
```

Più bassa è la durata, più frequente è il refresh, più i dati sono aggiornati.  
⚠️ Aumenta il carico di chiamate a KC.

---

### Soluzione 3 — Backchannel Logout _(la più corretta)_

KC supporta un meccanismo per cui quando un utente viene disabilitato o modificato, KC chiama direttamente la tua app su un endpoint dedicato per invalidare la sessione.

```
Admin disabilita utente su KC
        ↓
KC fa una POST alla tua app:
POST https://mia-app.test/backchannel-logout
  logout_token=eyJ...

La tua app riceve la notifica e distrugge la sessione
```

**Configurazione in KC:**

```
Clients → local-client-1 → Settings
→ Backchannel logout URL:              https://mia-app.test/backchannel-logout
→ Backchannel logout session required: ON
```

**Endpoint nella tua app:**

```php
// backchannel-logout.php

$logoutToken = $_POST['logout_token'] ?? '';

// Decodifica il logout token (anche questo è un JWT firmato da KC)
$payload = JWT::decode($logoutToken, JWK::parseKeySet($jwks));

// Contiene il session_id dell'utente da invalidare
$sessionId = $payload->sid;

// Trova e distruggi la sessione PHP corrispondente
// Devi aver salvato il sid KC in sessione al momento del login
session_id(cercaSessioneDaSid($sessionId));
session_start();
session_destroy();

http_response_code(200);
```

> Il `sid` è il **session ID di KC** — va salvato in `$_SESSION` al momento del login
> così da poter risalire alla sessione PHP da invalidare quando arriva la notifica.

---

### Soluzione 4 — Token Introspection _(più pesante)_

Ad ogni richiesta chiedi a KC se il token è ancora valido:

```php
// auth_check.php — ad ogni pagina protetta

$response = file_get_contents('http://keycloak-host/realms/Fonarcom/protocol/openid-connect/token/introspect', false,
    stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => 'Content-Type: application/x-www-form-urlencoded',
        'content' => http_build_query([
            'token'         => $_SESSION['kc_access_token'],
            'client_id'     => 'local-client-1',
            'client_secret' => 'il-tuo-secret',
        ]),
    ]])
);

$data = json_decode($response, true);

if (!$data['active']) {
    // Token revocato o utente disabilitato su KC
    session_destroy();
    header('Location: /login.php');
    exit;
}
```

KC risponde con `active: true/false` e i dati aggiornati dell'utente in tempo reale.  
⚠️ È una chiamata HTTP ad ogni richiesta — in produzione con traffico alto diventa un collo di bottiglia.

---

## Quale scegliere

| Scenario | Soluzione consigliata |
|---|---|
| Modifiche ruoli non urgenti | Token breve (1-2 min) + refresh |
| Disabilitazione account immediata | Backchannel logout |
| Massima sicurezza, traffico basso | Introspection |
| App semplice, contesto interno | Accetti il ritardo dei 5 minuti |

Per un'app legacy PHP in contesto interno, la combinazione **token breve + backchannel logout** copre il 95% dei casi senza overhead eccessivo.

## Riferimenti

- Keycloak Admin Console: `http://keycloak-host:8080`
- Endpoint JWKS (chiavi pubbliche): `http://keycloak-host:8080/realms/Fonarcom/protocol/openid-connect/certs`
- Discovery document (tutti gli endpoint del realm): `http://keycloak-host:8080/realms/Fonarcom/.well-known/openid-configuration`
- Libreria PHP usata per la verifica JWT: [`firebase/php-jwt`](https://github.com/firebase/php-jwt)
- Spec OAuth 2.0 Authorization Code Flow: [RFC 6749](https://datatracker.ietf.org/doc/html/rfc6749#section-4.1)
