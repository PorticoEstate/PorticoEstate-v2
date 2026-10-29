# Nøkkeladministrasjon (`keyadmin`) – plan og datamodell

> Status: planfase godkjent. Dokumentet er grunnlaget for implementasjonen.
> Basert på utkast til datamodell (`nøkkelbank.dia`) og kodebasens konvensjoner i PorticoEstate-v2.
> Prosess-sjekkliste (brukeravklaringer + teknisk gjennomføring): [sjekkliste.md](./sjekkliste.md)

## 1. Formål

Modulen håndterer **oppbevaring og administrasjon av utlån av nøkler** for et stort antall
boliger: fysiske nøkler og sylindre (låssystemer), nøkkelskap med krokplassering,
utlån med kvittering og depositum, automatiske purringer ved forfall, strekkode/QR-oppslag
og integrasjon mot elektroniske låser.

## 2. Navn og rammer

| Element | Verdi |
|---|---|
| Modulnavn (mappe/API) | `keyadmin` |
| Visningsnavn | «Nøkkeladministrasjon» (via språkfiler `phpgw_no.lang` / `phpgw_nn.lang` / `phpgw_en.lang`) |
| Tabellprefiks | `ka_` |
| API-prefiks | `/keyadmin/...` (JSON/REST) |
| Visninger | `/keyadmin/view/...` (Twig) |
| Boligkobling | `location_code` → `fm_locations` i property-modulen (samme mønster som rental) |

## 3. Arkitektur

Modulen følger de moderne konvensjonene i kodebasen (jf. `booking`-modulen):

```
src/modules/keyadmin/
├── setup/
│   ├── setup.inc.php            # versjon, app_order, migration_only = true, tabell-liste
│   ├── default_records.inc.php  # standard registry-verdier (sylindertyper m.m.)
│   └── phpgw_en|no|nn.lang      # oversettelser
├── migrations/                  # all skjemaendring som migrasjonsfiler (php migrate.php)
├── models/
│   ├── *.php                    # extends App\models\BaseModel
│   └── KeyadminGenericRegistry.php  # extends App\models\GenericRegistry
├── repositories/                # datatilgang
├── services/                    # forretningslogikk: utlån, retur, purring, kvittering
├── controllers/                 # REST/JSON-API (Slim 4, OpenAPI-attributter)
├── viewcontrollers/             # server-renderte sider via Twig (TwigHelper('keyadmin'))
├── html/                        # samlokaliserte visningskomponenter (messenger-mønsteret)
│   ├── _bare.twig               # minimal layout for rendring inne i legacy-rammen
│   └── base/
│       ├── keys/        keyadmin_keys.twig|css|js         # nøkkeloversikt (datatable)
│       ├── key_edit/    keyadmin_key_edit.twig|css|js
│       ├── cylinders/   keyadmin_cylinders.twig|css|js
│       ├── doors/       keyadmin_doors.twig|css|js
│       ├── cabinets/    keyadmin_cabinets.twig|css|js     # skap/krok
│       ├── borrowers/   keyadmin_borrowers.twig|css|js
│       ├── loan/        keyadmin_loan.twig|css|js         # utlevering (med skanning)
│       ├── return/      keyadmin_return.twig|css|js       # retur
│       ├── receipt/     keyadmin_receipt.twig|css        # kvittering (PDF/e-post)
│       └── reports/     keyadmin_reports.twig|css|js
├── tasks/                       # asynkrone jobber (purring ved forfall)
├── routes/Routes.php            # plukkes opp automatisk av src/routes/RegisterRoutes.php
└── doc/                         # dokumentasjon (dette dokumentet)
```

### Visningskomponenter (html/-konvensjonen fra messenger)

Hver visning er en samlokalisert komponent med egen Twig-mal, CSS og JS i samme mappe:
`html/base/<visning>/keyadmin_<visning>.{twig,css,js}`. Viewcontrollerne rendrer via
`TwigHelper('keyadmin')` med `@views/`-navnerommet, f.eks.
`@views/keys/keyadmin_keys.twig`, og `html/_bare.twig` som layout inne i legacy-rammen.

Oppslagsrekkefølge for `@views/<visning>/...` (første treff vinner, jf.
`phpgwapi/helpers/TwigHelper.php`):

1. `html/<template_set>/<visning>/...` – template-set-spesifikk override (f.eks. `html/digdir/keys/`)
2. `html/base/<visning>/...` – delt standardimplementasjon
3. `html/<visning>/...` – legacy flat layout (brukes ikke i ny modul)

Nøkkelpunkter:

- **Routes** registreres med `AccessVerifier` / `SessionsMiddleware` som i
  `src/modules/booking/routes/Routes.php`.
- **Oppslagstabeller** (sylindertype, e-låssystem, …) implementeres via
  `GenericRegistry` + `GenericRegistryController` – gir ferdig CRUD-API og UI
  (se `GLOBAL_REGISTRY_ARCHITECTURE.md` i repo-roten).
- **ACL**: standard rettighetssystem (READ/ADD/EDIT/DELETE) per seksjon.
- **Migrasjoner**: `migration_only = true` fra dag én – ingen legacy
  `tables_current.inc.php`.

## 4. Datamodell

### 4.1 Opprinnelig utkast (nøkkelbank.dia)

```
Sylindertype(id, navn)
System(id, systemnummer)
Rom(id, romnavn)
Dør(id, rom_id, dør_til)
Sylinder(id, sylindertype_id, systemnummer_id, syl.mrk, sylinderutførelse, dør_id, dørtykkelse)
sylinder_nøkkel(id, sylinder_id, nøkkel_id)            -- M:N
Nøkkel(id, K_N, navn, status, merknad)
Utlån(id, nøkkel_id, lånetaker_id, utlånsdato, innleveringsdato)
lånetaker(id, fornavn, etternavn, telefon, epost, firma_id)
firma(id, orgnr, navn, adresse, telefon, epost)
```

### 4.2 Mapping fra utkast til revidert modell

| Utkast | Revidert | Endringer |
|---|---|---|
| `Sylindertype` | registry-type `cylinder_type` | Ren oppslagstabell → `KeyadminGenericRegistry` |
| `System` | `ka_key_system` | + produsent, beskrivelse, merknad |
| `Rom` | *(utgår)* | Erstattes av `location_code` mot property-hierarkiet |
| `Dør` | `ka_door` | + `location_code`; `dørtykkelse` flyttes hit |
| `Sylinder` | `ka_cylinder` | + kind (fysisk/elektronisk), e-lås-felter |
| `sylinder_nøkkel` | `ka_cylinder_key` | Uendret M:N – kritisk for system-/hovednøkler |
| `Nøkkel` | `ka_key` | `status` → enum; + kopi_nr, barcode, hook_id, e-lås-felter |
| `Utlån` | `ka_loan` + `ka_loan_item` | Én kvittering kan dekke flere nøkler; + avtalt returdato, depositum, utlevert av |
| `lånetaker` | `ka_borrower` | + type, valgfri kobling mot `fm_tenant` |
| `firma` | `ka_company` | Beholdes; ev. senere kobling mot `fm_vendor` |

### 4.3 Revidert skjema (implementeres som migrasjoner)

```
ka_key_system
  id              serial PK
  systemnummer    varchar  UNIQUE NOT NULL
  produsent       varchar
  beskrivelse     varchar
  merknad         text

ka_door
  id              serial PK
  location_code   varchar  NOT NULL  → fm_locations.location_code (indeksert)
  beskrivelse     varchar            -- «dør til», f.eks. «Hovedinngang», «Bod kjeller»
  dortykkelse     int                -- mm; styrer sylinderlengde
  merknad         text

ka_cylinder
  id                 serial PK
  cylinder_type_id   int  → registry cylinder_type
  key_system_id      int  → ka_key_system
  door_id            int  → ka_door
  merking            varchar          -- syl.mrk
  utforelse          varchar          -- sylinderutførelse
  kind               varchar NOT NULL DEFAULT 'fysisk'   -- fysisk | elektronisk
  e_lock_system_id   int  NULL → registry e_lock_system
  e_lock_resource_id varchar NULL     -- ekstern ID i e-låssystemet
  merknad            text

ka_cylinder_key                        -- M:N: hvilke nøkler går til hvilke sylindre
  id              serial PK
  cylinder_id     int NOT NULL → ka_cylinder
  key_id          int NOT NULL → ka_key
  UNIQUE (cylinder_id, key_id)

ka_key
  id              serial PK
  key_system_id   int → ka_key_system
  nokkelnummer    varchar NOT NULL   -- K_N fra utkastet
  kopi_nr         int NOT NULL DEFAULT 1
  navn            varchar
  status          varchar NOT NULL DEFAULT 'pa_lager'
                  -- pa_lager | utlant | tapt | kassert | bestilt
                  -- vedlikeholdes av utlåns-/retur-/tapsflyten, aldri direkte redigerbar
  barcode         varchar UNIQUE     -- strekkode/QR for skanneoppslag
  hook_id         int NULL → ka_hook -- plassering i nøkkelskap
  kind            varchar NOT NULL DEFAULT 'fysisk'   -- fysisk | digital
  e_lock_key_id   varchar NULL       -- ekstern nøkkel-ID i e-låssystemet
  merknad         text
  UNIQUE (key_system_id, nokkelnummer, kopi_nr)

ka_cabinet
  id              serial PK
  navn            varchar NOT NULL
  location_code   varchar            -- hvor skapet står (indeksert)
  merknad         text

ka_hook
  id              serial PK
  cabinet_id      int NOT NULL → ka_cabinet
  kroknummer      varchar NOT NULL
  UNIQUE (cabinet_id, kroknummer)

ka_company
  id              serial PK
  orgnr           varchar UNIQUE
  navn            varchar NOT NULL
  adresse         varchar
  telefon         varchar
  epost           varchar

ka_borrower
  id              serial PK
  type            varchar NOT NULL   -- leietaker | firma_person | ansatt | annet
  fornavn         varchar
  etternavn       varchar
  telefon         varchar
  epost           varchar
  company_id      int NULL → ka_company
  tenant_id       int NULL → fm_tenant (property)
  aktiv           smallint NOT NULL DEFAULT 1

ka_loan                              -- «kvitteringen»
  id                serial PK
  borrower_id       int NOT NULL → ka_borrower
  utlant_av         int NOT NULL → phpgw_accounts (saksbehandler)
  utlansdato        timestamp NOT NULL
  avtalt_returdato  date               (indeksert – purrejobben)
  formal            varchar
  merknad           text
  deposit_amount    numeric(10,2) NULL
  deposit_status    varchar NOT NULL DEFAULT 'ingen'
                    -- ingen | innbetalt | refundert | inndratt
  kvittering_sendt  timestamp NULL

ka_loan_item                         -- den enkelte nøkkelen på kvitteringen
  id               serial PK
  loan_id          int NOT NULL → ka_loan
  key_id           int NOT NULL → ka_key
  returnert_dato   timestamp NULL
  returnert_til    int NULL → phpgw_accounts
  status           varchar NOT NULL DEFAULT 'utlant'
                   -- utlant | returnert | tapt

ka_reminder
  id              serial PK
  loan_id         int NOT NULL → ka_loan
  sendt_dato      timestamp NOT NULL
  type            varchar NOT NULL   -- epost | sms
  resultat        varchar

ka_event                             -- revisjonshistorikk per nøkkel
  id              serial PK
  key_id          int NOT NULL → ka_key
  loan_id         int NULL → ka_loan
  tidspunkt       timestamp NOT NULL
  account_id      int NOT NULL → phpgw_accounts
  hendelse        varchar NOT NULL
                  -- utlevert | returnert | purret | tapt | kassert | flyttet | opprettet
  detaljer        text
```

Registry-typer i `KeyadminGenericRegistry`:

- `cylinder_type` – sylindertyper (fra utkastets `Sylindertype`)
- `e_lock_system` – elektroniske låssystemer (mønster fra `bb_e_lock_system` i booking)

### 4.4 Designvalg

1. **`Rom` utgår** – døren pekes direkte til `location_code` (leilighet/etasje i
   property-hierarkiet). Finere romnivå dekkes av `ka_door.beskrivelse`.
2. **Innleveringsdato ligger på `ka_loan_item`** – nøkler fra samme kvittering kan
   returneres hver for seg. `ka_loan` er kvitteringen, `ka_loan_item` den enkelte nøkkelen.
3. **`ka_key.status` er enum**, ikke fri tekst, og settes kun av forretningslogikken
   (utlån/retur/tap/kassering) – aldri direkte i skjema.
4. **`ka_event`** gir full sporbarhet per nøkkel («hvem hadde nøkkelen når»).
5. **Dørtykkelse flyttes fra sylinder til dør** – det er døren som har tykkelse;
   sylinderutførelse/lengde avledes av den.
6. **Fysisk og digital nøkkel i samme modell** – `kind`-felt på både sylinder og nøkkel,
   med eksterne ID-felter mot e-låssystemet.

### 4.5 Indekser (stort volum)

| Indeks | Formål |
|---|---|
| `ka_key(barcode)` UNIQUE | Skanneoppslag ved utlevering/retur |
| `ka_key(key_system_id, nokkelnummer, kopi_nr)` UNIQUE | Identitet på fysisk nøkkel |
| `ka_door(location_code)` | Oppslag per bolig |
| `ka_cabinet(location_code)` | Oppslag per bolig/kontor |
| `ka_loan_item(key_id) WHERE returnert_dato IS NULL` | «Hvor er nøkkelen nå» |
| `ka_loan(avtalt_returdato)` | Purrejobben |

## 5. API-skisse

```
GET    /keyadmin/keys                     # paginert liste, filter på system/status/location
POST   /keyadmin/keys
GET    /keyadmin/keys/{id}
PATCH  /keyadmin/keys/{id}
GET    /keyadmin/keys/lookup/{barcode}    # skanneoppslag
GET    /keyadmin/keysystems | /cylinders | /doors | /cabinets | /borrowers | /companies
                                          # tilsvarende CRUD
POST   /keyadmin/loans                    # opprett utlån (flere nøkler) + kvittering
POST   /keyadmin/loans/{id}/return        # returner (alle eller utvalgte loan_items)
POST   /keyadmin/loans/{id}/extend        # forleng avtalt returdato
POST   /keyadmin/keys/{id}/lost           # meld tapt
GET    /keyadmin/reports/outstanding      # utestående nøkler per bolig/låntaker
GET    /keyadmin/registry/{type}          # GenericRegistry (cylinder_type, e_lock_system)

GET    /keyadmin/view/...                 # Twig-sider for alt over
```

## 6. Prosesser (services)

- **Utlevering**: velg låntaker + skann/velg nøkler → `ka_loan` + `ka_loan_item`,
  status på nøkler → `utlant`, kvittering genereres (Twig → PDF) og sendes på e-post
  (mønster fra `booking/services/EmailService.php`).
- **Retur**: skann nøkkel → finn åpent `loan_item` → sett returnert, status → `pa_lager`,
  ev. depositum refunderes.
- **Purring**: asynkron task (jf. `booking/tasks/`) finner forfalte lån uten retur og
  sender e-post/SMS; logges i `ka_reminder`.
- **Tapsmelding**: nøkkel → `tapt`, hendelse logges, ev. varsel om sylinderskifte for
  berørte sylindre (via `ka_cylinder_key`).

## 7. Faseplan

| Fase | Innhold | Status |
|---|---|---|
| 0 | Avklaring: navn, datamodell, integrasjoner | ✅ Ferdig |
| 1 | Grunnmur: modulskjelett, setup/migrasjoner, GenericRegistry, routes, ACL | ⬜ |
| 2 | Kjerneregister: nøkler, system, sylinder, dør, skap/krok – CRUD-API + Twig-UI med paginert søk/filter og oppslag mot `fm_locations` | ⬜ |
| 3 | Utlånsflyt: utlever/returner/forleng, kvittering (PDF/e-post), depositum, historikk | ⬜ |
| 4 | Varsling og rapporter: purre-task, utestående-rapporter, eksport | ⬜ |
| 5 | Polish: strekkodeskanning i UI (mobil), e-låsintegrasjon, dashboards | ⬜ |

## 8. Åpne spørsmål

- Skal depositum kobles mot økonomieksport (Agresso-mønsteret fra rental), eller kun
  registreres manuelt?
- SMS-purring: via eksisterende `sms`-modul?
- Hvilke e-låssystemer er aktuelle (leverandør-API)?
- Skal låntaker kunne slås opp/synkroniseres automatisk fra `fm_tenant`?
