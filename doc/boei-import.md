# BOEI: godkjenningsstyrt lokasjonsimport

Administrasjon -> Eiendom -> Lokasjon -> **BOEI importkandidater**
(`property.uianalyze_location.boei`).

## Oppsett

Oppgrader property-skjemaet til `0.9.17.768` via ordinær databaseoppgradering.
Oppdater språkfilene via ordinær språkadministrasjon. Integrasjonen forutsetter
BOEI-oppsettet med tilhørende egenskaper på lokasjonstabellene.

## Arbeidsflyt

1. Synkroniseringen oppdaterer BOEI-rådata. Den oppretter ikke lenger lokasjoner
   direkte fra BOEIs Objekt, Bygg, Seksjon eller Leieobjekt.
2. Nye leieobjekter legges i `boei_leieobjekt_candidate`, unikt på
   `(objekt_id, leie_id)`. Objektstatus lagres i `boei_objekt_candidate`.
   Nye kandidater får `street_id` fra `boei_leieobjekt.gateadresse_id` og
   `street_number` fra `boei_leieobjekt.gatenr`. Senere oppdateringer
   overskriver ikke disse kandidatverdiene.
   Knappen **Oppdater kandidater** kan også oppdatere kandidatlisten fra siste
   innleste rådata, uten å kontakte BOEI.
3. Åpne objektet. For nye objekter: registrer gnr./bnr. og godkjenn objektet.
   Eksisterende objekter kan få nye leieobjekter uten ny objektgodkjenning.
4. Registrer bygningsnummer, nasjonal gatekode, husnummer/bokstav og ADF på
   hvert leieobjekt. Gatekoden er en offentlig referanse og trenger ikke
   finnes i gateregisteret. Intern `street_id` bevares fra BOEI, kan ikke
   endres i skjemaet og må finnes i `fm_streetaddress.id` ved godkjenning
   og import. Gatekoden brukes ikke til å utlede eller endre `street_id`.
   BOEI-adressen vises som referanse med gatenavn fra `fm_streetaddress.descr`
   (koblet via BOEIs `gateadresse_id`), husnummer/bokstav og gate-ID.
   **Lagre utkast** tillater ufullstendige opplysninger og opphever eventuell
   godkjenning. **Lagre og godkjenn** krever komplette opplysninger.
5. Bekreft og importer godkjente kandidater fra siden, eller la neste
   synkronisering importere dem. Et objekt med sine godkjente leieobjekter
   importeres i én transaksjon. Feil gir tilbakerulling og lar godkjenningene
   stå for retting og nytt forsøk.

BOEI-ID-ene beholdes som `loc1` og `loc4`. Ett BOEI-objekt er én eiendom,
selv om flere objekter har samme gnr./bnr. Gnr./bnr. bevares i kandidatregisteret.
Bygg (`loc2`) tildeles pr. bygningsnummer innenfor objektet. Inngang (`loc3`)
tildeles pr. gate og normalisert husnummer/bokstav innenfor bygget.
Begge nivåene opprettes også ved bare ett bygg/en inngang, med ledige nummer
fra `01` til `99`. `loc4` er ikke et nytt løpenummer pr. inngang.

Eksisterende entydige bygg/innganger gjenbrukes. Tvetydige eksisterende
plasseringer blokkerer import og må korrigeres først; ingen eksisterende
leieobjekter flyttes automatisk. Byggnavn oppsummerer adressene, mens
inngangsnavn er gatenavn + husnummer/bokstav. Alle fire nivåer registreres
også i `fm_locations`.

Manuelle opplysninger og godkjenninger overlever oppdatering av rådata.
Importerte kandidater beholdes som kobling og revisjonsspor med godkjenner,
godkjenningstid, importtid og lokasjonskode. De kan ikke redigeres på
kandidatsiden. Senere BOEI-synkronisering oppdaterer fortsatt driftsdata,
men overskriver ikke adressen til kandidatimporterte enheter eller
bygg-/inngangsnavn. Arealsummer beregnes fra faktisk importerte leieobjekter,
ikke fra BOEIs bygg-/seksjonsinndeling eller ventende kandidater.

## Verifikasjon

Kjør eksisterende PHPUnit med
`BOEI_TEST_DOMAIN=portico_ebf_20260922 vendor/bin/phpunit --do-not-cache-result tests/services/BoeiImportServiceTest.php`.
Testene krever PostgreSQL og BOEI-skjemaet, men bruker kun forbindelseslokale
midlertidige tabeller med `search_path = pg_temp`. De skriver ikke til
applikasjonens permanente tabeller og kontakter ikke BOEI.
