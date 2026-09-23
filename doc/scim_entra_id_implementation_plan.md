# Implementeringsplan: SCIM 2.0 med Microsoft Entra ID

## 1. Mål

Implementere en SCIM 2.0-provider i PorticoEstate slik at Microsoft Entra ID kan provisionere og administrere brukere og grupper automatisk.

SCIM skal håndtere:

- opprettelse av brukere
- oppdatering av navn, e-post og status
- aktivering og deaktivering av brukere
- sletting eller kontrollert deaktivering
- oppslag og filtrering av brukere
- opprettelse og oppdatering av grupper
- gruppemedlemskap
- idempotent synkronisering fra Entra ID

OIDC/OAuth-innlogging skal fortsatt håndteres av eksisterende `OpenIDConnect` og `Auth_Azure`. SCIM er en separat provisioning-kanal og skal ikke implementeres i innloggingsflyten.

## 2. Lokale arkitekturforutsetninger

Eksisterende integrasjonspunkter:

- Route-registrering skjer modulvis via `src/routes/RegisterRoutes.php`.
- API-ruter for `phpgwapi` registreres i `src/modules/phpgwapi/routes/Routes.php`.
- Kontooperasjoner er samlet i `src/modules/phpgwapi/controllers/Accounts/Accounts.php`.
- SQL- og medlemskapslogikk finnes i `src/modules/phpgwapi/controllers/Accounts/AccountsSql.php`.
- Konto- og gruppedata ligger primært i `phpgw_accounts`, `phpgw_group_map` og `phpgw_accounts_data`.
- PHPUnit 9 er tilgjengelig via Composer.
- PHPCS brukes for kompatibilitetskontroll av `src`.

SCIM skal ikke bruke:

- `SessionsMiddleware`, fordi Entra ikke har en phpGroupWare-sesjon
- `Auth_Azure::get_username()`, fordi den er del av runtime-innlogging
- `ApiKeyVerifier`, fordi denne bruker `X-API-User` og passord i stedet for SCIM bearer-token
- legacy UI-klasser som primær provisioning-API

## 3. Foreslått komponentstruktur

Opprett følgende komponenter:

```text
src/modules/phpgwapi/middleware/ScimAuthMiddleware.php
src/modules/phpgwapi/controllers/ScimController.php
src/modules/phpgwapi/services/ScimService.php
src/modules/phpgwapi/services/ScimResourceMapper.php
src/modules/phpgwapi/services/ScimFilterParser.php
src/modules/phpgwapi/services/ScimProvisioningRepository.php
```

Suppler eventuelt databasen med:

```text
phpgw_scim_mapping
```

En egen mapping-tabell anbefales fremfor å basere identitet på `account_lid` eller et løst JSON-felt. Den skal koble Entra sin stabile objekt-ID til lokal konto og SCIM-resource-ID.

## 4. SCIM-kontrakt

Base URL:

```text
https://<host>/api/scim/v2
```

Minimumsendepunkter for første versjon:

```text
GET    /ServiceProviderConfig
GET    /ResourceTypes
GET    /Schemas

GET    /Users
POST   /Users
GET    /Users/{id}
PUT    /Users/{id}
PATCH  /Users/{id}
DELETE /Users/{id}

GET    /Groups
POST   /Groups
GET    /Groups/{id}
PATCH  /Groups/{id}
DELETE /Groups/{id}
```

Følgende SCIM-skjemaer skal brukes:

```text
urn:ietf:params:scim:schemas:core:2.0:User
urn:ietf:params:scim:schemas:core:2.0:Group
urn:ietf:params:scim:api:messages:2.0:ListResponse
urn:ietf:params:scim:api:messages:2.0:PatchOp
urn:ietf:params:scim:api:messages:2.0:Error
```

### 4.1 Brukermapping

| SCIM-attributt | Lokal verdi |
|---|---|
| `id` | Stabil SCIM-ID, aldri et tilfeldig ID ved hvert oppslag |
| `externalId` | Entra brukerens stabile objekt-ID |
| `userName` | `account_lid` |
| `name.givenName` | `account_firstname` |
| `name.familyName` | `account_lastname` |
| `displayName` | Beregnet visningsnavn eller lagret profilverdi |
| `active` | `account_status = 'A'` når true |
| `active` | `account_status != 'A'` når false |
| `emails[type eq "work"].value` | E-post i konto-/kontaktdata |
| `groups[].value` | Lokal gruppe-ID via mapping |

Passord skal ikke provisioneres gjennom SCIM. Entra ID er identitetskilden.

### 4.2 Gruppe- og medlemskapsmapping

| SCIM-attributt | Lokal verdi |
|---|---|
| `id` | Stabil SCIM-gruppe-ID |
| `externalId` | Entra gruppe-ID |
| `displayName` | Gruppenavn |
| `members[].value` | SCIM-bruker-ID |
| medlemskap | `phpgw_group_map` |

Gruppenavn må håndteres deterministisk. Hvis `displayName` endres, skal lokal gruppe oppdateres uten at en ny gruppe opprettes.

## 5. Identitet og lagring

### 5.1 Mapping-tabell

Anbefalt tabell:

```sql
CREATE TABLE phpgw_scim_mapping (
    scim_id varchar( UUID ) PRIMARY KEY,
    external_id varchar(255) NOT NULL UNIQUE,
    account_id integer NOT NULL UNIQUE,
    resource_type varchar(32) NOT NULL,
    created_at timestamp NOT NULL,
    updated_at timestamp NOT NULL
);
```

Den faktiske SQL-syntaksen må tilpasses databaseversjon og prosjektets eksisterende setup-mønster.

Krav:

- `external_id` skal være unik
- `account_id` skal være unik per resource type
- oppslag på `externalId` skal bruke indeks
- sletting av lokal konto skal ikke føre til at en ny SCIM-ID tildeles ved neste synkronisering uten en eksplisitt migrering
- alle writes skal være transaksjonelle når konto og mapping oppdateres sammen

### 5.2 Idempotens

`POST /Users` må først søke etter eksisterende `externalId`. Dersom brukeren allerede finnes, skal tjenesten ikke opprette en duplikatkonto.

Det samme gjelder grupper og gruppe-medlemskap.

Konkurrerende provisioning-kall skal håndteres med unik databaseconstraint og kontrollert behandling av duplicate-key-feil.

## 6. Sikkerhet

### 6.1 Bearer-token

SCIM-endepunktene skal beskyttes med en egen `ScimAuthMiddleware`.

Middleware skal:

1. lese `Authorization`-headeren
2. kreve formatet `Bearer <token>`
3. hente forventet token fra miljøvariabel eller Secret Manager
4. bruke konstant-tids sammenligning
5. returnere HTTP 401 uten å lekke om tokenet var delvis korrekt
6. ikke skrive tokenet til logger

Foreslått konfigurasjon:

```text
SCIM_BEARER_TOKEN=<lang tilfeldig hemmelig verdi>
```

Tokenet skal kunne roteres uten kodeendring. I produksjon bør det ligge i en hemmelighetsløsning eller container secret.

### 6.2 HTTP- og input-sikkerhet

- krev HTTPS i produksjon
- valider JSON body før behandling
- avvis ukjente eller ugyldige operasjoner med SCIM-feilrespons
- bruk parameteriserte databasekall
- tillat kun støttede filterfelt og operatorer
- begrens maksimal `count` for listekall
- ikke logg personnummer, bearer-token eller komplette access tokens
- logg korrelasjons-ID og Entra external ID når det er nødvendig for feilsøking

## 7. Milepæler

### Milepæl 0: Kontrakt og beslutningsgrunnlag

**Mål:** Frys SCIM-kontrakten før produksjonskode skrives.

Oppgaver:

- bekreft hvilke Entra-attributter som skal være authoritative
- bestem om sletting betyr fysisk sletting eller deaktivering
- bestem hvilke grupper som skal provisioneres
- bestem hvilke kontoer som er manuelt administrerte og derfor ikke skal overskrives
- velg mapping-tabell og ID-strategi
- dokumenter miljøvariabel for bearer-token
- dokumenter forventet tenant URL

Leveranse:

- ferdig attribute mapping
- beslutning om delete/deactivate
- godkjent endpointliste
- testdata for én bruker og én gruppe

Exit-kriterium:

- ingen åpne beslutninger som påvirker ressurs-ID, konto-status eller slettelogikk

### Milepæl 1: SCIM-konfigurasjon og autentisering

**Mål:** Etablere en isolert og sikker SCIM-inngang.

Implementasjon:

- legg til `ScimAuthMiddleware`
- legg til `ServiceProviderConfig`, `ResourceTypes` og `Schemas`
- registrer `/api/scim/v2` i `src/modules/phpgwapi/routes/Routes.php`
- standardiser SCIM JSON- og error-responser
- legg til request correlation ID i logging der prosjektets logger tillater det

Tester:

- manglende Authorization gir 401
- feil scheme gir 401
- feil token gir 401
- riktig token slipper gjennom
- tokenet vises ikke i logger
- metadata-endepunktene returnerer riktige schemas

Exit-kriterium:

- Entra `Test Connection` kan nå metadata-endepunktet og får HTTP 200

### Milepæl 2: Mapping repository og lesemodell

**Mål:** Lage stabil identitet og lesing av lokale kontoer/grupper.

Implementasjon:

- opprett mapping-tabell i setup/migrering
- implementer `ScimProvisioningRepository`
- implementer oppslag på `scim_id`
- implementer oppslag på `external_id`
- implementer mapping mellom kontoobjekt og SCIM User
- implementer mapping mellom gruppeobjekt og SCIM Group
- legg inn databaseconstraints og nødvendige indekser

Tester:

- mapping kan opprettes og leses
- samme external ID kan ikke registreres to ganger
- ikke-eksisterende mapping gir 404 fra resource-endepunkt
- status mappes riktig begge veier
- sensitiv konto-/autentiseringsinformasjon kommer ikke med i SCIM-respons

Exit-kriterium:

- samme lokale konto gir samme SCIM resource-ID ved gjentatte kall

### Milepæl 3: User read API

**Mål:** Gjøre eksisterende brukere synlige for Entra.

Implementasjon:

- `GET /Users/{id}`
- `GET /Users`
- støtte `filter=userName eq "..."`
- støtte `filter=externalId eq "..."`
- støtte `startIndex` og `count`
- returner korrekt `ListResponse`
- returner 404 for ukjent ressurs
- avgrens liste til kontoer som skal være SCIM-managed

Tester:

- hent bruker på ID
- hent bruker på externalId-filter
- hent bruker på userName-filter
- ugyldig filter gir SCIM-feil, ikke SQL-feil
- pagination returnerer riktig `totalResults`, `startIndex` og `itemsPerPage`
- tomt resultat returnerer gyldig ListResponse
- brukerens passordhash returneres aldri

Exit-kriterium:

- Entra kan lese en testbruker og liste brukere uten manuell databaseintervensjon

### Milepæl 4: User write API

**Mål:** Støtte opprettelse og oppdatering av brukere.

Implementasjon:

- `POST /Users`
- `PUT /Users/{id}`
- `PATCH /Users/{id}`
- valider obligatorisk `userName`
- oppdater navn, e-post og active-status
- gjør POST idempotent på `externalId`
- bruk eksisterende `Accounts`/`AccountsSql` for kontoopprettelse og oppdatering
- lag mapping i samme transaksjon som kontoen når mulig
- håndter duplicate `userName` og `externalId` med SCIM-konfliktrespons

PATCH-operasjoner som minimum:

```text
Replace active
Replace userName
Replace name.givenName
Replace name.familyName
Replace displayName
Replace emails
```

Tester:

- opprett ny bruker
- opprett samme externalId to ganger uten duplikat
- oppdater navn
- oppdater e-post
- deaktiver bruker
- reaktiver bruker
- avvis manglende userName
- avvis konflikt på userName
- avvis konflikt på externalId
- håndter ukjent bruker med 404
- håndter ugyldig PatchOp
- kontroller transaksjonsrollback ved delvis feil

Exit-kriterium:

- Entra kan opprette og oppdatere en testbruker, og lokal konto får forventet status og profilinformasjon

### Milepæl 5: Groups og medlemskap

**Mål:** Synkronisere grupper og gruppemedlemskap.

Implementasjon:

- `GET /Groups`
- `POST /Groups`
- `GET /Groups/{id}`
- `PATCH /Groups/{id}`
- `DELETE /Groups/{id}`
- støtte add/remove av medlemmer i PATCH
- koble medlemskap til `Accounts::add_user2group()` og `delete_account4group()` eller tilsvarende repository-metoder
- bruk mapping mellom Entra-gruppe og lokal gruppe
- avgjør eksplisitt om lokale manuelle medlemmer skal beholdes eller fjernes ved full synkronisering

Tester:

- opprett gruppe
- endre gruppenavn
- legg til medlem
- fjern medlem
- gjenta samme medlemskapsoperasjon uten feil
- ukjent medlems-ID gir kontrollert feil
- slett/deaktiver gruppe etter avtalt policy
- verifiser at lokale medlemskapsrelasjoner ikke blir duplisert

Exit-kriterium:

- Entra kan opprette en gruppe og synkronisere minst én bruker inn og ut av gruppen

### Milepæl 6: Entra integrasjonstest

**Mål:** Verifisere den virkelige provisioning-flyten med Microsoft Entra ID.

Oppgaver:

- opprett Enterprise Application i Entra
- sett Provisioning til Automatic
- konfigurer Tenant URL og Secret Token
- start med `Sync only assigned users and groups`
- tildel én testbruker og én testgruppe
- kjør `Test Connection`
- kjør initial provisioning
- observer Entra provisioning logs
- sammenlign Entra-objekt, SCIM-respons og lokal konto
- test rename, disable, re-enable og medlemskapsendringer

Tester:

- initial user create
- user update
- user disable
- user re-enable
- group create
- group membership add/remove
- retry etter midlertidig HTTP 5xx
- request med ugyldig data

Exit-kriterium:

- Entra viser vellykket provisioning uten gjentatte retries eller duplikate lokale kontoer

### Milepæl 7: Produksjonsherding og utrulling

**Mål:** Gjøre løsningen driftsklar.

Oppgaver:

- legg inn rate limiting eller reverse-proxy-beskyttelse
- etabler token-rotasjonsprosedyre
- etabler backup/migrering av mapping-tabell
- legg til structured logging og alarmer på 4xx/5xx
- dokumenter rollback
- dokumenter hvordan en feilprovisionert konto stoppes
- kjør full PHPUnit-suite
- kjør PHP lint og PHPCS/PHPCompatibility på nye filer
- oppdater OpenAPI-dokumentasjon dersom SCIM-endepunktene skal eksponeres der

Exit-kriterium:

- deploy kan gjentas uten manuelle databaseendringer
- driftsteamet kan rotere token og feilsøke en provisioning-feil
- rollback-prosedyre er testet

## 8. Teststrategi

### 8.1 Enhetstester

Plasser nye enhetstester under `tests/services` eller `tests/controllers` i tråd med eksisterende struktur.

Foreslåtte tester:

```text
tests/services/ScimResourceMapperTest.php
tests/services/ScimFilterParserTest.php
tests/services/ScimProvisioningRepositoryTest.php
tests/controllers/ScimControllerTest.php
tests/middleware/ScimAuthMiddlewareTest.php
```

Enhetstestene skal mocke database- og kontoavhengigheter der det er mulig. De skal dekke mapping, filterparser, error-responser og idempotensregler uten å kreve Entra.

### 8.2 Controller- og kontrakttester

Test HTTP-kontrakten for hvert endepunkt:

- statuskode
- `Content-Type: application/scim+json` der SCIM krever det
- `schemas`
- feltnavn og datatyper
- `ListResponse`
- SCIM error-format
- `Location`-header etter POST der det er relevant

### 8.3 Database/integrasjonstester

Med testdatabase skal følgende verifiseres:

- konto og mapping opprettes atomisk
- duplicate external ID stoppes av både applikasjon og constraint
- medlemskap kan legges til og fjernes
- deaktivering endrer status uten å miste mapping
- transaksjon rollbacker ved feil i andre write-trinn

### 8.4 Entra smoke-test

En egen manuell eller automatisert smoke-test skal dokumentere:

```text
1. Test Connection
2. Provision én bruker
3. Verifiser lokal konto
4. Endre navn i Entra
5. Verifiser lokal oppdatering
6. Deaktiver bruker i Entra
7. Verifiser lokal inaktiv konto
8. Reaktiver bruker
9. Verifiser lokal aktiv konto
10. Provision gruppe og medlemskap
```

## 9. Verifikasjonskommandoer

Kommandoene må kjøres fra repository-roten og tilpasses miljøet:

```bash
php -l src/modules/phpgwapi/controllers/ScimController.php
php -l src/modules/phpgwapi/services/ScimService.php
php -l src/modules/phpgwapi/middleware/ScimAuthMiddleware.php

vendor/bin/phpunit tests/controllers/ScimControllerTest.php
vendor/bin/phpunit tests/services/ScimResourceMapperTest.php
vendor/bin/phpunit tests/middleware/ScimAuthMiddlewareTest.php

vendor/bin/phpcs --standard=phpcs.xml.dist src/modules/phpgwapi/controllers/ScimController.php
vendor/bin/phpcs --standard=phpcs.xml.dist src/modules/phpgwapi/services/ScimService.php
```

Etter at nye tester er etablert, kjør også hele relevante testsettet:

```bash
vendor/bin/phpunit tests/controllers tests/services tests/middleware
```

## 10. Feilkoder og responsregler

Minimum:

| Situasjon | HTTP |
|---|---:|
| Manglende/ugyldig token | 401 |
| Ugyldig SCIM-data | 400 |
| Ressurs finnes ikke | 404 |
| Duplikat externalId/userName | 409 |
| Ugyldig filter | 400 |
| Midlertidig database-/infrastrukturfeil | 500 eller 503 |

Alle feil skal følge SCIM error-format og ikke returnere stack trace eller SQL-detaljer.

## 11. Avhengigheter og rekkefølge

```text
Milepæl 0
    -> Milepæl 1
    -> Milepæl 2
    -> Milepæl 3
    -> Milepæl 4
    -> Milepæl 5
    -> Milepæl 6
    -> Milepæl 7
```

Følgende kan utføres parallelt etter at kontrakten i Milepæl 0 er frosset:

- testskjelett for mapper og filterparser
- dokumentasjon av Entra-attributtmapping
- database-/migreringsskisse
- logging- og observability-design

Controllerimplementasjon bør vente til mapping- og feilkodene er besluttet, ellers risikerer man å låse inn feil SCIM-kontrakt.

## 12. Akseptansekriterier for ferdig implementasjon

Løsningen er ferdig når:

- Entra `Test Connection` lykkes
- alle obligatoriske metadata-endepunkter svarer korrekt
- Entra kan opprette én bruker uten lokal duplikat
- Entra kan oppdatere brukerens navn og e-post
- Entra kan deaktivere og reaktivere brukeren
- Entra kan opprette og oppdatere grupper
- gruppemedlemskap synkroniseres begge veier i provisioning-flyten
- `externalId` og SCIM-ID er stabile
- ugyldige kall gir SCIM-kompatible feil
- bearer-token aldri logges
- enhetstester, controller-tester og relevante integrasjonstester er grønne
- PHP lint og PHPCS/PHPCompatibility er grønne for alle nye filer
- token-rotasjon og rollback er dokumentert

## 13. Åpne beslutninger før implementering

- Skal Entra eie alle felter, eller skal enkelte lokale felter være skrivebeskyttet?
- Skal `DELETE` fysisk slette konto eller bare sette den inaktiv?
- Skal eksisterende lokale kontoer kobles ved `userName`, e-post eller en forhåndsimportert mapping?
- Skal alle Entra-grupper synkroniseres, eller bare grupper som er tildelt Enterprise Application?
- Skal manuelle lokale gruppemedlemmer beholdes ved full medlemskapssynkronisering?
- Hvor skal bearer-token lagres i hvert miljø?
- Hvilken logging og alarm skal brukes ved gjentatte provisioning-feil?

Disse beslutningene bør være avklart før Milepæl 1 avsluttes.
