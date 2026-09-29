# SCIM 2.0 med Microsoft Entra ID

Denne veiledningen beskriver hvordan SCIM-provisionering settes opp, testes og driftes i produksjon for PorticoEstate.

## 1. Kort forklart

Microsoft Entra ID sender SCIM-kall til PorticoEstate når en tildelt bruker eller gruppe opprettes, endres, deaktiveres eller fjernes.

```text
Microsoft Entra ID
        |
        | HTTPS + bearer-token
        v
/api/scim/v2
        |
        +-- Users
        +-- Groups
        +-- gruppemedlemskap
        |
phpgw_accounts / phpgw_mapping / phpgw_group_map
```

SCIM erstatter ikke innloggingen:

- OIDC autentiserer brukeren når vedkommende logger inn.
- SCIM oppretter og vedlikeholder den lokale kontoen på forhånd.
- Entra sender ikke brukerens passord gjennom SCIM.

## 2. Dette må være klart

Før oppsettet starter, må følgende være tilgjengelig:

- offentlig HTTPS-adresse til PorticoEstate
- Microsoft Entra tenant-ID
- tilgang til å opprette eller administrere en Enterprise Application i Entra
- tilgang til produksjonens secret manager eller containerkonfigurasjon
- tilgang til å kjøre PorticoEstate setup-oppgradering
- databasebackup og tilgang til PostgreSQL for verifikasjon
- én testbruker og én testgruppe i Entra

Anbefalt utrulling er å starte med kun testbrukeren og testgruppen. Ikke aktiver synkronisering for alle brukere før hele sjekklisten er bestått.

## 3. Verdier som skal konfigureres

| Variabel | Eksempel | Formål |
|---|---|---|
| `SCIM_TENANT_ID` | `11111111-2222-3333-4444-555555555555` | Avgrenser alle mappinger til riktig Entra-tenant |
| `SCIM_BEARER_TOKEN` | Lang tilfeldig hemmelig verdi | Autentiserer Entra mot SCIM-endepunktet |
| `SCIM_PUBLIC_BASE_URL` | `https://portico.example.no/api/scim/v2` | Offentlig URL brukt i SCIM `meta.location` |

`SCIM_PUBLIC_BASE_URL` skal ikke ha avsluttende skråstrek. Koden tåler den, men en kanonisk verdi gjør drift og logging enklere.

### Generer token

Generer minst 32 tilfeldige byte, for eksempel:

```bash
openssl rand -base64 48
```

Behandle tokenet som et passord:

- ikke legg det i Git
- ikke skriv det i dokumentasjon, logger eller supportsaker
- lagre det i secret manager eller beskyttet runtime-konfigurasjon
- begrens hvem som kan lese og rotere det

## 4. Produksjonsoppsett i PorticoEstate

### 4.1 Konfigurer runtime-miljøet

Docker Compose videresender variablene kun til `slim`-containeren:

```bash
export SCIM_TENANT_ID='11111111-2222-3333-4444-555555555555'
export SCIM_BEARER_TOKEN='<secret>'
export SCIM_PUBLIC_BASE_URL='https://portico.example.no/api/scim/v2'

docker compose up -d --force-recreate slim
```

I produksjon bør variablene komme fra plattformens secret/config-løsning, ikke fra en versjonskontrollert `.env`-fil.

Bekreft bare at variablene finnes. Ikke skriv tokenverdien til terminal eller logg:

```bash
docker compose exec slim sh -lc '
  test -n "$SCIM_TENANT_ID" &&
  test -n "$SCIM_BEARER_TOKEN" &&
  test -n "$SCIM_PUBLIC_BASE_URL"
'
```

### 4.2 Reverse proxy og TLS

Krav:

- SCIM må være tilgjengelig med gyldig HTTPS-sertifikat
- offentlig URL må nå `/api/scim/v2`
- proxy/brannmur må tillate `GET`, `POST`, `PUT`, `PATCH` og `DELETE`
- `Authorization`-headeren må videresendes uendret
- request body må ikke filtreres bort for `PATCH`
- WAF-regler må tillate SCIM-filter i query string, inkludert anførselstegn
- responsstørrelse og timeout må være tilstrekkelig for inntil 100 ressurser per side

`SCIM_PUBLIC_BASE_URL` gjør genererte resource-URL-er uavhengige av intern container-host og proxyens scheme.

## 5. Første tekniske kontroll

Test discovery-endepunktet fra en godkjent administrasjonsmaskin:

```bash
curl --fail-with-body \
  -H "Authorization: Bearer $SCIM_BEARER_TOKEN" \
  -H 'Accept: application/scim+json' \
  "$SCIM_PUBLIC_BASE_URL/ServiceProviderConfig"
```

Forventet:

- HTTP 200
- `Content-Type: application/scim+json`
- `patch.supported` er `true`
- `bulk.supported` er `false`
- `filter.maxResults` er `100`

Negativ kontroll med feil token skal gi HTTP 401:

```bash
curl --silent --output /dev/null --write-out '%{http_code}\n' \
  -H 'Authorization: Bearer invalid' \
  "$SCIM_PUBLIC_BASE_URL/ServiceProviderConfig"
```

Hvis serveren mangler `SCIM_BEARER_TOKEN`, svarer den HTTP 503. Dette er tilsiktet fail-closed oppførsel.

## 6. Oppsett i Microsoft Entra ID

Navn i Entra-portalen kan variere noe mellom portalversjoner.

### 6.1 Opprett Enterprise Application

1. Åpne **Microsoft Entra admin center**.
2. Gå til **Identity** → **Applications** → **Enterprise applications**.
3. Velg **New application**.
4. Velg å opprette en egen applikasjon utenfor galleriet.
5. Velg alternativet for å integrere en annen applikasjon som ikke finnes i galleriet.
6. Gi applikasjonen et tydelig navn, for eksempel `PorticoEstate SCIM Production`.

Bruk en separat Enterprise Application for test og produksjon.

### 6.2 Konfigurer provisioning

1. Åpne Enterprise Application.
2. Velg **Provisioning**.
3. Velg automatisk provisioning.
4. Sett **Tenant URL** til:

```text
https://portico.example.no/api/scim/v2
```

5. Sett **Secret Token** til samme verdi som `SCIM_BEARER_TOKEN`.
6. Velg **Test Connection**.
7. Ikke start full provisioning før attributtmapping og scope er kontrollert.

Tenant URL er SCIM-base-URL-en, ikke `/Users` eller `/ServiceProviderConfig`.

## 7. Attributtmapping

### 7.1 Brukere

Anbefalt mapping:

| Entra-attributt | SCIM-attributt | Kommentar |
|---|---|---|
| `objectId` | `externalId` | Obligatorisk stabil Entra-identitet |
| Valgt innloggingsattributt | `userName` | Må samsvare med lokal OIDC/SSO-innlogging |
| `givenName` | `name.givenName` | Obligatorisk i dagens implementasjon |
| `surname` | `name.familyName` | Obligatorisk i dagens implementasjon |
| `displayName` | `displayName` | Returneres til Entra |
| `mail` | `emails[type eq "work"].value` | Lagres som `scim_email` i kontodata |
| Soft-delete/assignment-uttrykk | `active` | Styrer `phpgw_accounts.account_status` |

### Valg av `userName`

`userName` blir lokal `account_lid`. Velg derfor attributtet som OIDC-innloggingen faktisk produserer:

- bruk `userPrincipalName` dersom lokal konto og OIDC bruker full UPN
- bruk `onPremisesSamAccountName` dersom lokal innlogging bruker AD-kortnavn
- ikke bytt mapping etter produksjonsstart uten kontrollert rename-test

`account_id` er den egentlige og stabile lokale identiteten. Endring av `userName` endrer `account_lid`, men SCIM-koblingen forblir stabil via `phpgw_mapping.account_id`.

### 7.2 Aktiv status

Entra må sende `active = false` når en bruker fjernes fra scope eller soft-deletes. Bruk Entra sitt vanlige uttrykk for `IsSoftDeleted`, tilpasset portalens mappingeditor.

Verifiser alltid med **Provision on demand** at:

- aktiv bruker gir `true`
- deaktivert eller unassigned bruker gir `false`

`DELETE /Users/{id}` utfører også soft delete og setter lokal konto inaktiv. Kontoen slettes ikke fysisk.

### 7.3 Grupper

Anbefalt mapping:

| Entra-attributt | SCIM-attributt |
|---|---|
| `objectId` | `externalId` |
| `displayName` | `displayName` |
| gruppemedlemmer | `members` |

Gruppemedlemmer må være provisionerte SCIM-brukere i samme tenant før de kan legges til gruppen.

## 8. Scope og første utrulling

1. Sett scope til **Sync only assigned users and groups**.
2. Tildel én dedikert testbruker.
3. Tildel én dedikert testgruppe som inneholder testbrukeren.
4. Bruk **Provision on demand** for testbrukeren.
5. Kontroller lokal konto og mapping.
6. Provision testgruppen.
7. Test endring, deaktivering, reaktivering og medlemskap.
8. Start ordinær provisioning først når alle tester er grønne.

Ikke bruk **Sync all users and groups** som første produksjonssteg.

## 9. Automatisk smoke-test

Smoke-testen gjør faktiske writes og rydder testressursene med soft delete til slutt.

Kjør fra repository-roten:

```bash
SCIM_BASE_URL='https://portico.example.no/api/scim/v2' \
SCIM_BEARER_TOKEN='<secret>' \
SCIM_SMOKE_ALLOW_WRITES=1 \
php test_scripts/scim_entra_smoke.php
```

Testen dekker:

1. discovery
2. oppretting av bruker
3. oppslag med `externalId`-filter
4. path-less Entra PATCH
5. deaktivering og reaktivering
6. oppretting av gruppe
7. add/remove av medlem
8. kontrollert cleanup

Kjør testen i testmiljø før Entra kobles til produksjon. I produksjon skal den bare kjøres i et avtalt vedlikeholdsvindu med godkjent testkonto-policy.

## 10. Praktisk bruk

### Opprette bruker

1. Opprett brukeren i Entra.
2. Sørg for at obligatoriske navn- og innloggingsattributter er satt.
3. Tildel brukeren til PorticoEstate Enterprise Application.
4. Vent på ordinær provisioning eller bruk **Provision on demand**.
5. Brukeren opprettes lokalt og kan logge inn via OIDC når tilgang er gitt.

### Endre påloggingsnavn

1. Endre det authoritative attributtet i Entra.
2. Entra sender SCIM PATCH/PUT.
3. Lokal `account_lid` oppdateres.
4. Stabil `account_id` og Entra `externalId` beholdes.
5. Verifiser at OIDC leverer samme nye brukernavn før brukeren forsøker å logge inn.

### Deaktivere bruker

Fjern brukeren fra applikasjonens scope eller deaktiver brukeren etter virksomhetens policy. Entra sender `active = false` eller DELETE. PorticoEstate setter kontoen inaktiv, men beholder stabil mapping og historiske referanser.

### Reaktivere bruker

Tildel brukeren på nytt eller aktiver den i Entra. SCIM setter lokal konto aktiv igjen. Den eksisterende `account_id` brukes fortsatt.

### Administrere gruppe

Tildel gruppen til Enterprise Application. Entra oppretter gruppen og sender medlemsendringer. Add/remove er idempotent. Manuelle lokale medlemskap fjernes ikke med mindre Entra eksplisitt sender remove for medlemmet.

## 11. Datamodell

### `phpgw_accounts`

- `account_id`: stabil lokal bruker-/gruppeidentitet og SCIM `id`
- `account_lid`: muterbart påloggingsnavn eller gruppenavn
- `account_status`: `A` for aktiv, `I` for inaktiv
- `account_type`: `u` for bruker, `g` for gruppe

### `phpgw_mapping`

- `ext_user`: Entra object ID og SCIM `externalId`
- `auth_type`: alltid `scim` for SCIM-rader
- `location`: Entra tenant-ID
- `account_id`: autoritativ kobling til lokal konto
- `account_lid`: kompatibilitetskopi for eksisterende SSO-kode
- `status`: mappingstatus, ikke brukerens SCIM `active`

### `phpgw_accounts_data`

Arbeids-e-post lagres i JSONB som:

```json
{
  "scim_email": "user@example.no"
}
```

Dette oppdaterer ikke automatisk kontaktregisterets work-email-felt.

### `phpgw_group_map`

Lagrer koblingen mellom lokal gruppe-ID og lokal bruker-ID.

## 12. Støttet funksjonalitet

Støttet:

- `eq`-filter for `userName`, `externalId` og gruppe-`displayName`
- pagination med maksimalt 100 ressurser per side
- User CRUD med soft delete
- User PATCH `Replace`
- path-less Entra Replace-objekt
- Group create/read/PATCH/soft delete
- medlem add/remove

Ikke støttet:

- SCIM Bulk
- ETag/versioning
- sortering
- passordprovisionering
- avanserte filteroperatorer som `co`, `sw`, `and` og `or`
- custom schema extensions
- fysisk sletting gjennom SCIM

## 13. Sikker drift

- Begrens SCIM-URL-en til Microsoft/avtalte nettverk dersom infrastrukturen tillater det.
- Rate-limit endepunktet i reverse proxy eller WAF.
- Ikke logg `Authorization`-headeren.
- Masker persondata i request/response-debugging.
- Overvåk HTTP 401, 409, 429 og 5xx.
- Alarmer på gjentatte feil fra samme provisioning-jobb.
- Ta backup av `phpgw_mapping` før større mappingendringer.
- Bruk separate tokens for test og produksjon.

### Tokenrotasjon

Implementasjonen støtter ett aktivt token om gangen. Rotasjon må derfor koordineres:

1. pause Entra provisioning
2. oppdater secret i PorticoEstate runtime
3. recreat/restart `slim`-containeren
4. oppdater Secret Token i Entra
5. kjør **Test Connection**
6. start provisioning igjen

## 14. Overvåking og kontrollspørringer

SCIM-mappinger per tenant:

```sql
SELECT location, COUNT(*)
FROM phpgw_mapping
WHERE auth_type = 'scim'
GROUP BY location;
```

Mappinger uten gyldig konto:

```sql
SELECT m.ext_user, m.location, m.account_id, m.account_lid
FROM phpgw_mapping m
LEFT JOIN phpgw_accounts a ON a.account_id = m.account_id
WHERE m.auth_type = 'scim'
  AND a.account_id IS NULL;
```

Flere SCIM-identiteter mot samme konto:

```sql
SELECT account_id, location, COUNT(*)
FROM phpgw_mapping
WHERE auth_type = 'scim'
  AND account_id IS NOT NULL
GROUP BY account_id, location
HAVING COUNT(*) > 1;
```

Kontroller at lagret kompatibilitetsnavn samsvarer:

```sql
SELECT m.account_id, m.account_lid AS mapped_lid, a.account_lid AS current_lid
FROM phpgw_mapping m
JOIN phpgw_accounts a ON a.account_id = m.account_id
WHERE m.auth_type = 'scim'
  AND m.account_lid <> a.account_lid;
```

Avvik i siste spørring bryter ikke SCIM-oppslaget, siden `account_id` er autoritativt, men bør ryddes for konsistent administrasjon.

## 15. Feilsøking

### Test Connection gir 401

Kontroller:

- samme token i Entra og `SCIM_BEARER_TOKEN`
- ingen ekstra whitespace ved kopiering
- proxy videresender `Authorization`
- `slim`-containeren er restartet etter secret-endring

### Test Connection gir 503

`SCIM_BEARER_TOKEN` mangler eller er tom i PHP-runtime.

### Provisioning gir 400 invalidFilter

Entra sender et filter utenfor støttet profil. Kontroller attributtmapping og provisioning logs. Støttede felt er `userName`, `externalId` og `displayName`, med operatoren `eq`.

### Provisioning gir 409 uniqueness

Kontroller:

- eksisterende lokal `account_lid`
- eksisterende `externalId` i samme tenant
- flere SCIM-identiteter mot samme `account_id`
- provisioning logs for hvilken ressurs Entra forsøker å opprette

Ikke opprett ny lokal konto manuelt før konflikten er forstått.

### Bruker opprettes, men kan ikke logge inn

SCIM og OIDC bruker sannsynligvis ulike innloggingsverdier. Sammenlign:

- SCIM `userName`
- lokal `phpgw_accounts.account_lid`
- OIDC claim konfigurert som brukernavn

### `meta.location` viser intern host eller HTTP

Sett korrekt `SCIM_PUBLIC_BASE_URL` og restart `slim`-containeren.

### Gruppe-medlem avvises

Brukeren må være provisionert som SCIM-bruker i samme tenant før Entra legger vedkommende til gruppen.

### Setup stopper på duplikater

Kjør duplikatspørringen i kapittel 14. Avklar korrekt eier og rydd mappingene manuelt før oppgraderingen kjøres igjen.

## 16. Produksjonssjekkliste

- [ ] Databasebackup er tatt og verifisert
- [ ] `phpgwapi` er oppgradert til `0.9.17.570`
- [ ] `phpgw_mapping.account_id` finnes
- [ ] SCIM-mappinger er backfillet
- [ ] Partial-indeksen finnes
- [ ] `SCIM_TENANT_ID` er satt
- [ ] `SCIM_BEARER_TOKEN` ligger i secret manager
- [ ] `SCIM_PUBLIC_BASE_URL` peker til offentlig HTTPS-URL
- [ ] Proxy videresender Authorization og PATCH
- [ ] Discovery returnerer HTTP 200
- [ ] Feil token returnerer HTTP 401
- [ ] Entra Test Connection lykkes
- [ ] Testbruker kan provisioneres
- [ ] Rename er testet
- [ ] Deaktivering og reaktivering er testet
- [ ] Testgruppe og medlemskap er testet
- [ ] Provisioning logs er uten gjentatte feil
- [ ] Overvåking og alarmer er aktivert
- [ ] Tokenrotasjon er dokumentert hos drift

## 17. Relaterte filer

```text
src/modules/phpgwapi/controllers/ScimController.php
src/modules/phpgwapi/middleware/ScimAuthMiddleware.php
src/modules/phpgwapi/services/ScimProvisioningRepository.php
src/modules/phpgwapi/services/ScimResourceMapper.php
src/modules/phpgwapi/services/ScimFilterParser.php
src/modules/phpgwapi/services/ScimResponse.php
src/modules/phpgwapi/routes/Routes.php
src/modules/phpgwapi/setup/tables_current.inc.php
src/modules/phpgwapi/setup/tables_update.inc.php
test_scripts/scim_entra_smoke.php
doc/scim_entra_id_implementation_plan.md
```
