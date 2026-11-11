# Sjekkliste – nøkkeladministrasjon (`keyadmin`)

> Arbeidsdokument for prosessen videre. Del A brukes i diskusjon med brukere og
> medarbeidere; del B er den tekniske gjennomføringen. Kryss av underveis.
> Se [plan.md](./plan.md) for arkitektur og datamodell.

## Del A – Avklaringer med brukere og medarbeidere

### A1. Arbeidsflyt og hverdagsbruk

- [ ] Hvem skal bruke systemet daglig (vaktmestere, boligkontor, servicetorg)?
- [ ] Hvordan foregår utlevering i dag – hva fungerer, hva er smertepunktene?
- [ ] Skal utlevering kunne skje fra flere steder/kontorer samtidig?
- [ ] Hvor lang er typisk utlånstid (håndverker: timer/dager, leietaker: hele leieforholdet)?
- [ ] Skal leietakers «faste» nøkler registreres som et (langvarig) utlån, eller som en egen
      tildelingstype?
- [ ] Trengs kvittering med signatur ved utlevering (papir, skjerm-signatur, kun e-post)?
- [ ] Hva skjer i praksis når en nøkkel ikke leveres tilbake i tide?

### A2. Omfang og volum

- [ ] Antall boliger, nøkler og låssystemer som skal inn i systemet?
- [ ] Finnes dagens data i et system/regneark som skal **migreres inn**? (format, kvalitet)
- [ ] Hvor mange nøkkelskap finnes, og hvor står de?
- [ ] Er alle nøkler merket i dag – og med hva (gravering, nummer, strekkode)?

### A3. Roller og tilgang (→ ACL-design)

- [ ] Hvilke roller trengs (f.eks. les, utlever/motta, administrere register, konfigurasjon)?
- [ ] Skal tilgang avgrenses geografisk (per område/avdeling/`location_code`-gren)?
- [ ] Hvem skal kunne melde nøkkel tapt og vedta sylinderskifte?

### A4. Depositum og økonomi

- [ ] Brukes depositum i dag? For hvem (alle, kun eksterne)?
- [ ] Faste satser eller per tilfelle?
- [ ] Skal depositum kobles mot økonomisystem (Agresso-mønsteret fra rental), eller
      kun registreres manuelt? *(åpent spørsmål fra plan pkt. 8)*
- [ ] Hva skjer med depositum ved tapt nøkkel – og skal faktisk kostnad (sylinderskifte)
      kunne viderefaktureres?

### A5. Varsling og purring

- [ ] Purring på e-post, SMS eller begge? (SMS: via eksisterende `sms`-modul?)
- [ ] Hvor mange purringer, med hvilke intervaller, og hva er eskaleringen etterpå?
- [ ] Skal saksbehandler også varsles internt om forfalte lån?

### A6. Strekkode/QR og utstyr

- [ ] Finnes skannere/etikettskrivere i dag, eller skal mobilkamera brukes?
- [ ] Skal systemet generere og skrive ut etiketter (format/størrelse)?
- [ ] Praktisk merking: tåler nøklene etikett, eller trengs gravering + oppslag?

### A7. Elektroniske låser

- [ ] Hvilke e-låssystemer/leverandører er aktuelle (API-tilgang)? *(åpent spørsmål, plan pkt. 8)*
- [ ] Skal modulen bare **registrere** digitale nøkler, eller også **provisjonere**
      (opprette/trekke tilbake) via leverandør-API?
- [ ] Hvordan håndteres bygg med både fysiske og digitale låser?

### A8. Integrasjoner og registre

- [ ] Skal låntakere slås opp/synkroniseres fra `fm_tenant` (property)? *(åpent spørsmål, plan pkt. 8)*
- [ ] Skal firma kobles mot leverandørregisteret (`fm_vendor`)?
- [ ] Er `fm_locations`-hierarkiet komplett og vedlikeholdt for alle boligene?

### A9. Personvern og lagring (GDPR)

- [ ] Hvilke persondata lagres om låntakere, og hva er behandlingsgrunnlaget?
- [ ] Hvor lenge skal utlånshistorikk oppbevares – sletting/anonymisering etter X år?
- [ ] Hvem skal ha innsyn i historikken (`ka_event`)?

### A10. Rapporter og oppfølging

- [ ] Hvilke rapporter trengs fra dag én (utestående per bolig/låntaker, forfalte lån,
      tapte nøkler, nøkler per låssystem)?
- [ ] Behov for eksport (Excel/CSV) eller utskrifter?

### A11. Oppsummering av innspillsrunden

- [ ] Innspill samlet og prioritert (må ha / bør ha / kan vente)
- [ ] [plan.md](./plan.md) oppdatert: datamodell, API og faseplan justert etter innspill
- [ ] Åpne spørsmål i plan pkt. 8 besvart og lukket
- [ ] Omfang for versjon 1 besluttet og forankret

## Del B – Teknisk gjennomføring

### B1. Fase 1 – grunnmur

- [ ] `setup/setup.inc.php` (versjon, `app_order`, `migration_only = true`, tabell-liste)
- [ ] Første migrasjon: alle `ka_`-tabeller med indekser (plan pkt. 4.3/4.5)
- [ ] `KeyadminGenericRegistry` med `cylinder_type` og `e_lock_system`
- [ ] `setup/default_records.inc.php` (standard sylindertyper m.m.)
- [ ] `routes/Routes.php` med `AccessVerifier`/`SessionsMiddleware`
- [ ] Språkfiler `phpgw_en|no|nn.lang`
- [ ] `html/_bare.twig` + katalogstruktur etter messenger-mønsteret
- [ ] ACL-seksjoner definert (etter A3)
- [ ] Modulen installerer/registrerer i setup og vises i navigasjonen

### B2. Fase 2 – kjerneregister

- [ ] Modeller (`BaseModel`) + repositories for system, dør, sylinder, nøkkel, skap/krok,
      låntaker, firma
- [ ] CRUD-API med paginering/filter (system, status, `location_code`)
- [ ] Oppslag mot `fm_locations` (typeahead/velger)
- [ ] Twig-UI: `keys`, `key_edit`, `cylinders`, `doors`, `cabinets`, `borrowers`
- [ ] Skanneoppslag `GET /keyadmin/keys/lookup/{barcode}`
- [ ] Ev. datamigrering fra dagens kilde (etter A2)

### B3. Fase 3 – utlånsflyt

- [ ] `LoanService`: utlever (flere nøkler per kvittering), returner, forleng, meld tapt
- [ ] Statusmaskin for `ka_key.status` (kun via service, aldri direkte)
- [ ] Hendelseslogg `ka_event` på alle overganger
- [ ] Kvittering: Twig → PDF + e-postutsending (ev. signatur etter A1)
- [ ] Depositum-håndtering (etter A4)
- [ ] Twig-UI: `loan`, `return`, `receipt`

### B4. Fase 4 – varsling og rapporter

- [ ] Purre-task (asynkron jobb) etter reglene fra A5, logget i `ka_reminder`
- [ ] Rapporter/`reports`-UI + eksport (etter A10)

### B5. Fase 5 – polish og utvidelser

- [ ] Strekkodeskanning med mobilkamera i UI (etter A6)
- [ ] Etikettgenerering/utskrift (etter A6)
- [ ] E-låsintegrasjon mot valgt leverandør (etter A7)
- [ ] Sletting/anonymisering av historikk (etter A9)

### B6. Kvalitet og utrulling

- [ ] Tester for services (utlån/retur/status-overganger) og API
- [ ] Migrasjon kjørt og verifisert i testmiljø (`php migrate.php run --module=keyadmin`)
- [ ] Ytelsestest med realistisk volum (antall fra A2)
- [ ] Brukerdokumentasjon / hurtigguide for utlevering og retur
- [ ] Pilot med utvalgte brukere → justeringer → produksjonssetting
