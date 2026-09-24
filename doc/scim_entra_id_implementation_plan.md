# Implementeringsplan: SCIM 2.0 med Microsoft Entra ID

## Implementeringsstatus

| Milepæl | Status | Merknad |
|---|---|---|
| 0. Kontrakt og beslutninger | Fullført | Beslutninger dokumentert nedenfor |
| 1. Konfigurasjon og autentisering | Fullført | Middleware- og metadata-tester grønne 2026-09-24 |
| 2. Mapping repository og lesemodell | Fullført | Parser-, mapper- og repositorytester grønne 2026-09-24 |
| 3. User read API | Fullført | Read-kontrakt dekket av controller-tester 2026-09-24 |
| 4. User write API | Fullført | Create, PUT, PATCH og soft delete implementert 2026-09-24 |
| 5. Groups og medlemskap | Fullført | Group CRUD og atomisk medlemskaps-PATCH implementert 2026-09-24 |
| 6. Entra integrasjonstest | Blokkert eksternt | Smoke-test klar; mangler deployet URL, tenant-ID og token |
| 7. Produksjonsherding | Pågår | Lokal lint, kompatibilitet og testkontroll fullført |

Status oppdateres etter hver milepæl når tilhørende tester og exit-kriterier er bestått.

Produksjonsoppsett og praktisk bruk er dokumentert i `doc/scim_entra_production_guide.md`.

### Siste validering

2026-09-24:

- 27 fokuserte PHPUnit-tester med 70 assertions bestått
- PHP lint bestått for alle nye og endrede SCIM-filer
- PHPCompatibility/PHPCS bestått for alle nye produksjonsklasser
- Docker Compose-konfigurasjon med `SCIM_TENANT_ID` og `SCIM_BEARER_TOKEN` validert
- `git diff --check` bestått
- smoke-testens fail-closed guard bestått
- virkelig Entra provisioning-test ikke kjørt fordi testmiljøets URL, tenant-ID og bearer-token ikke er tilgjengelige i arbeidsmiljøet

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

Gjenbruk eksisterende mapping-tabell:

```text
phpgw_mapping
```

Tabellen brukes allerede til å koble eksterne SSO-identiteter til lokale kontoer. For SCIM brukes `ext_user` som Entra `externalId`, mens `account_id` er både den autoritative lokale koblingen og verdien som eksponeres som SCIM `id`. `account_lid` beholdes som kompatibilitetsdata for eksisterende SSO-kode, men brukes ikke som SCIM-relasjonsnøkkel.

## 4. SCIM-kontrakt

### 4.0 Støttet Entra-profil

Første versjon implementerer bevisst et smalt delsett av SCIM 2.0 som brukes av Microsoft Entra provisioning:

- bearer-token mot alle SCIM-ruter
- discovery via `ServiceProviderConfig`, `ResourceTypes` og `Schemas`
- `eq`-filter for `userName`, `externalId` og gruppe-`displayName`
- pagination med `startIndex` og `count`, maksimalt 100 resultater
- User `POST`, `GET`, `PUT`, `PATCH` og soft `DELETE`
- User PATCH `Replace` med eksplisitt path eller path-less value-objekt
- boolean-verdier både som JSON boolean og Entra-strengene `True`/`False` i PATCH
- Group `POST`, `GET`, `PATCH` og soft `DELETE`
- Group PATCH for `displayName`, `Add members` og `Remove members[value eq "<id>"]`

Avanserte SCIM-filtre, sortering, bulk, ETag, passordendring og egendefinerte schema extensions er ikke støttet i første versjon. Nye operasjoner skal bare legges til når de observeres i Entra provisioning logs og dekkes av kontrakttester.

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
| `id` | Stabil lokal `account_id`, serialisert som streng |
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
| `id` | Stabil lokal gruppe-`account_id`, serialisert som streng |
| `externalId` | Entra gruppe-ID |
| `displayName` | Gruppenavn |
| `members[].value` | SCIM-bruker-ID |
| medlemskap | `phpgw_group_map` |

Gruppenavn må håndteres deterministisk. Hvis `displayName` endres, skal lokal gruppe oppdateres uten at en ny gruppe opprettes.

## 5. Identitet og lagring

### 5.1 Gjenbruk av `phpgw_mapping`

Eksisterende tabell:

```sql
CREATE TABLE public.phpgw_mapping (
        ext_user varchar(100) NOT NULL,
        auth_type varchar(25) NOT NULL,
        status char(1) NOT NULL DEFAULT 'A',
        location varchar(200) NOT NULL,
        account_lid varchar(100) NOT NULL,
        account_id integer NULL,
        PRIMARY KEY (ext_user, location, auth_type)
);
```

SCIM-semantikk:

| `phpgw_mapping` | SCIM-bruk |
|---|---|
| `ext_user` | Entra objekt-ID / SCIM `externalId` |
| `auth_type` | Konstant verdi `scim` |
| `location` | Entra tenant-ID, eventuelt stabil applikasjons-ID dersom flere Enterprise Applications brukes i samme tenant |
| `account_lid` | Bakoverkompatibel kopi av påloggingsnavnet for eksisterende SSO-kode |
| `account_id` | Autoritativ SCIM-kobling til `phpgw_accounts.account_id` |
| `status` | Om identitetsmappingen er tillatt; ikke det samme som SCIM `active` |

SCIM `id` settes til lokal `phpgw_accounts.account_id`, serialisert som en streng. `account_id` lagres også i `phpgw_mapping` og er stabil ved endring av brukernavn. Den fungerer for både bruker- og gruppekontoer og oppfyller SCIM-kravet om en stabil resource-ID. Resource type utledes fra `phpgw_accounts.account_type` (`u` eller `g`).

Denne modellen krever ingen ny tabell eller migrering for første versjon. Repositoryet bør bruke en eksplisitt join:

```sql
SELECT a.account_id, a.account_type, a.account_lid, m.ext_user, m.status
FROM phpgw_mapping m
JOIN phpgw_accounts a ON a.account_id = m.account_id
WHERE m.ext_user = :external_id
    AND m.location = :tenant_id
    AND m.auth_type = 'scim'
```

Krav:

- `ext_user` skal inneholde Entra objekt-ID, ikke UPN eller e-post
- `location` skal alltid avgrense Entra-tenant eller provisioning-kilde
- eksisterende primærnøkkel sikrer unik `externalId` per tenant og auth type
- repositoryet skal avvise tvetydige koblinger der flere SCIM-identiteter peker til samme lokale konto innen samme tenant
- `account_id` skal aldri endres ved rename
- `account_lid`-kopien oppdateres i samme transaksjon av hensyn til eksisterende SSO- og administrasjonskode
- eksisterende `Mapping`-oppslag skal bruke `account_id` for å hente gjeldende `phpgw_accounts.account_lid`, med fallback til mappingens `account_lid` for eldre rader uten `account_id`
- SCIM `active` skal styre `phpgw_accounts.account_status`; `phpgw_mapping.status` skal ikke brukes som kontostatus
- oppslag på `externalId` skal bruke indeks
- sletting av lokal konto må også rydde SCIM-mappingen, eller helst erstattes med deaktivering
- alle writes skal være transaksjonelle når konto og mapping oppdateres sammen

Anbefalt PostgreSQL-indeks:

```sql
CREATE UNIQUE INDEX phpgw_mapping_scim_account_uidx
ON public.phpgw_mapping (account_id, location)
WHERE auth_type = 'scim' AND account_id IS NOT NULL;
```

Indeksen hindrer at flere Entra-identiteter i samme tenant kobles til samme lokale konto. Den er partiell for ikke å endre semantikken til eksisterende `remoteuser`-, `shibboleth`- eller andre SSO-mappinger. Før indeksen opprettes, backfiller migreringen `account_id` fra eksisterende `account_lid` og kontrollerer duplikate SCIM-rader for kombinasjonen `account_id` og `location`. Eventuelle duplikater skal rapporteres og ryddes eksplisitt; migreringen skal ikke velge eller slette en mapping automatisk.

Begrensninger:

- `account_id` er nullable for å bevare eldre eller foreldreløse SSO-mappinger under migrering. Nye SCIM-rader skal alltid ha `account_id`.
- Tabellen har foreløpig ingen fremmednøkkel. Sletting må derfor fortsatt rydde mappingen eksplisitt.
- En rename utenfor SCIM bryter ikke identitetskoblingen: `account_id` forblir stabil, og SSO-oppslaget returnerer det aktuelle påloggingsnavnet fra `phpgw_accounts`.
- `ext_user` er begrenset til 100 tegn. Entra object ID passer, men vilkårlige lange SCIM external IDs gjør ikke nødvendigvis det.
- Tabellen har ikke timestamps. Revisjon av provisioning må derfor håndteres i applikasjonslogger eller en senere audit-tabell.
- Eksisterende `Mapping`-klasse er laget for SSO-brukere. SCIM bør få et eget repository mot samme tabell, slik at gruppeoppslag, tenant-avgrensning og transaksjoner blir eksplisitte.

En egen `phpgw_scim_mapping`-tabell bør først vurderes senere dersom SCIM trenger opaque UUID-er som `id`, historikk/timestamps, soft delete av mappinger eller flere provisioning-kilder med mer metadata enn dagens sammensatte nøkkel støtter.

### 5.2 Idempotens

`POST /Users` må først søke etter `ext_user = externalId`, avgrenset med `location` og `auth_type = 'scim'`. Dersom brukeren allerede finnes, skal tjenesten ikke opprette en duplikatkonto.

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

**Status: Fullført.**

Beslutninger for første versjon:

- Entra er authoritative for `userName`, navn, arbeids-e-post, `active`, gruppenavn og SCIM-administrerte medlemskap.
- Felt som ikke inngår i SCIM-mappingen skal ikke overskrives.
- `DELETE` deaktiverer brukeren eller gruppen; fysisk sletting er ikke del av første versjon.
- Bare brukere og grupper som tildeles Enterprise Application provisioneres.
- Eksisterende lokale kontoer kobles bare gjennom eksplisitt `externalId`-mapping; automatisk kobling på e-post eller UPN utføres ikke.
- Manuelle medlemskap som ikke er kjent som SCIM-administrerte beholdes. SCIM add/remove gjelder bare medlemskap som behandles i den aktuelle provisioning-operasjonen.
- `location` inneholder Entra tenant-ID. Hvis flere Enterprise Applications i samme tenant senere trenger isolasjon, utvides verdien deterministisk med applikasjons-ID.
- Bearer-token leses fra `SCIM_BEARER_TOKEN` i runtime-miljøet.

Oppgaver:

- bekreft hvilke Entra-attributter som skal være authoritative
- bestem om sletting betyr fysisk sletting eller deaktivering
- bestem hvilke grupper som skal provisioneres
- bestem hvilke kontoer som er manuelt administrerte og derfor ikke skal overskrives
- bekreft gjenbruk av `phpgw_mapping`, tenant-verdi i `location` og lokal `account_id` som SCIM-ID
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

**Status: Fullført 2026-09-24.**

Implementert i `ScimAuthMiddleware`, `ScimController` og `ScimResponse`. Fokuserte tester: 7 tester og 23 assertions. PHP-syntakskontroll er bestått.

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

**Status: Fullført 2026-09-24.**

Implementert i `ScimFilterParser`, `ScimResourceMapper` og `ScimProvisioningRepository`. PostgreSQL upgrade `0.9.17.570` legger til den partielle unike SCIM-indeksen etter duplikatkontroll. Fokuserte tester: 8 tester og 16 assertions.

Implementasjon:

- implementer SCIM-oppslag mot eksisterende `phpgw_mapping`
- implementer `ScimProvisioningRepository`
- implementer oppslag på lokal `account_id` som SCIM-ID
- implementer oppslag på `ext_user`, `location` og `auth_type = 'scim'`
- implementer mapping mellom kontoobjekt og SCIM User
- implementer mapping mellom gruppeobjekt og SCIM Group
- opprett den partielle unike PostgreSQL-indeksen for `(account_id, location)` der `auth_type = 'scim'`
- verifiser at eksisterende primærnøkkel støtter oppslag på `externalId`

Tester:

- SCIM-mapping kan opprettes og leses uten å påvirke eksisterende SSO-mappinger
- samme external ID kan ikke registreres to ganger i samme tenant
- samme external ID kan eksistere i to tenants uten kollisjon
- samme lokale konto kan ikke kobles til flere SCIM-identiteter i samme tenant
- eksisterende mappinger med andre `auth_type` påvirkes ikke av den partielle indeksen
- endring av `account_lid` oppdaterer konto og mapping atomisk
- ikke-eksisterende mapping gir 404 fra resource-endepunkt
- status mappes riktig begge veier
- sensitiv konto-/autentiseringsinformasjon kommer ikke med i SCIM-respons

Exit-kriterium:

- samme lokale konto gir samme SCIM resource-ID ved gjentatte kall

### Milepæl 3: User read API

**Mål:** Gjøre eksisterende brukere synlige for Entra.

**Status: Fullført 2026-09-24.**

`GET /Users` og `GET /Users/{id}` støtter tenant-avgrenset lesing, `eq`-filter, pagination, stabil lokal SCIM-ID og SCIM-feilresponser.

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

**Status: Fullført 2026-09-24.**

`POST`, `PUT`, `PATCH` og `DELETE /Users/{id}` er implementert. Opprettelse er idempotent på `externalId`, rename oppdaterer konto og mapping i samme transaksjon, og DELETE deaktiverer kontoen. Controllerkontrakten har samlet 11 tester og 34 assertions. Database- og Entra-smoke-test utføres i Milepæl 6.

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

**Status: Fullført 2026-09-24.**

Group read/create/PATCH/soft delete og tenant-avgrenset medlemskap er implementert. Group PATCH validerer alle SCIM-medlemmer før én atomisk transaksjon utfører rename, add og remove. Add/remove er idempotent.

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

**Status: Blokkert av eksternt testmiljø.**

`test_scripts/scim_entra_smoke.php` er klar og dekker metadata, user create/filter/path-less PATCH/deactivate/reactivate, group create, medlemskap add/remove og kontrollert cleanup. Lokalt miljø mangler per 2026-09-24 `SCIM_BASE_URL`, `SCIM_BEARER_TOKEN`, `SCIM_TENANT_ID` og en kjørende web/database-stack.

Kjør mot testmiljø uten å lagre tokenet i shell history eller repository:

```bash
SCIM_BASE_URL=https://<test-host>/api/scim/v2 \
SCIM_BEARER_TOKEN=<secret> \
SCIM_SMOKE_ALLOW_WRITES=1 \
php test_scripts/scim_entra_smoke.php
```

`SCIM_TENANT_ID` skal være konfigurert i webapplikasjonens runtime-miljø, ikke i smoke-testklienten.

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

**Status: Pågår.** Lokal PHP lint, PHPUnit og PHPCompatibility er bestått. Ekstern Entra-verifisering, observability og utrullingskontroll gjenstår.

Lokal validering omfatter 27 tester og 70 assertions. `docker compose config --quiet`, PHP lint, PHPCompatibility og `git diff --check` er bestått. `SCIM_TENANT_ID`, `SCIM_BEARER_TOKEN` og `SCIM_PUBLIC_BASE_URL` videresendes kun til `slim`-containeren.

Oppgaver:

- legg inn rate limiting eller reverse-proxy-beskyttelse
- etabler token-rotasjonsprosedyre
- etabler backup av `phpgw_mapping` og rollback for SCIM-rader
- kontroller eksisterende SCIM-duplikater før den partielle unike indeksen opprettes
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

- konto og SCIM-rad i `phpgw_mapping` opprettes atomisk
- duplicate external ID i samme tenant stoppes av både applikasjon og eksisterende primærnøkkel
- eksisterende `remoteuser`- og `shibboleth`-mappinger påvirkes ikke
- rename av `account_lid` oppdaterer konto og mapping i samme transaksjon
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
