<?php


 function autoload_0e0f1a3209be4bb8fba2a53011be8bbb($class)
{
    $classes = array(
        'OppdateringService' => __DIR__ .'/OppdateringService.php',
        'KodeListe' => __DIR__ .'/KodeListe.php',
        'Kode' => __DIR__ .'/Kode.php',
        'DokumentListe' => __DIR__ .'/DokumentListe.php',
        'Dokument' => __DIR__ .'/Dokument.php',
        'DokumenttypeListe' => __DIR__ .'/DokumenttypeListe.php',
        'Dokumenttype' => __DIR__ .'/Dokumenttype.php',
        'Filinnhold' => __DIR__ .'/Filinnhold.php',
        'Filreferanse' => __DIR__ .'/Filreferanse.php',
        'DokumentstatusListe' => __DIR__ .'/DokumentstatusListe.php',
        'Dokumentstatus' => __DIR__ .'/Dokumentstatus.php',
        'FilListe' => __DIR__ .'/FilListe.php',
        'Fil' => __DIR__ .'/Fil.php',
        'FormatListe' => __DIR__ .'/FormatListe.php',
        'Format' => __DIR__ .'/Format.php',
        'TilknyttetRegistreringSomListe' => __DIR__ .'/TilknyttetRegistreringSomListe.php',
        'TilknyttetRegistreringSom' => __DIR__ .'/TilknyttetRegistreringSom.php',
        'VariantformatListe' => __DIR__ .'/VariantformatListe.php',
        'Variantformat' => __DIR__ .'/Variantformat.php',
        'KoordinatsystemKodeListe' => __DIR__ .'/KoordinatsystemKodeListe.php',
        'KoordinatsystemKode' => __DIR__ .'/KoordinatsystemKode.php',
        'FlateListe' => __DIR__ .'/FlateListe.php',
        'Flate' => __DIR__ .'/Flate.php',
        'Geometri' => __DIR__ .'/Geometri.php',
        'KoordinatListe' => __DIR__ .'/KoordinatListe.php',
        'Koordinat' => __DIR__ .'/Koordinat.php',
        'Kurve' => __DIR__ .'/Kurve.php',
        'PunktListe' => __DIR__ .'/PunktListe.php',
        'Punkt' => __DIR__ .'/Punkt.php',
        'RingListe' => __DIR__ .'/RingListe.php',
        'Ring' => __DIR__ .'/Ring.php',
        'BboxListe' => __DIR__ .'/BboxListe.php',
        'Bbox' => __DIR__ .'/Bbox.php',
        'OmraadeListe' => __DIR__ .'/OmraadeListe.php',
        'Omraade' => __DIR__ .'/Omraade.php',
        'AnsvarligEnumListe' => __DIR__ .'/AnsvarligEnumListe.php',
        'AnsvarligEnum' => __DIR__ .'/AnsvarligEnum.php',
        'SoekeOperatorEnumListe' => __DIR__ .'/SoekeOperatorEnumListe.php',
        'SoekeOperatorEnum' => __DIR__ .'/SoekeOperatorEnum.php',
        'BboxKriterie' => __DIR__ .'/BboxKriterie.php',
        'Ansvarlig' => __DIR__ .'/Ansvarlig.php',
        'KriterieListe' => __DIR__ .'/KriterieListe.php',
        'Kriterie' => __DIR__ .'/Kriterie.php',
        'Soekefelt' => __DIR__ .'/Soekefelt.php',
        'SoekskriterieListe' => __DIR__ .'/SoekskriterieListe.php',
        'Soekskriterie' => __DIR__ .'/Soekskriterie.php',
        'AdministrativenhetsnummerListe' => __DIR__ .'/AdministrativenhetsnummerListe.php',
        'Administrativenhetsnummer' => __DIR__ .'/Administrativenhetsnummer.php',
        'Fylke' => __DIR__ .'/Fylke.php',
        'Kommune' => __DIR__ .'/Kommune.php',
        'NasjonalArealplanIdListe' => __DIR__ .'/NasjonalArealplanIdListe.php',
        'NasjonalArealplanId' => __DIR__ .'/NasjonalArealplanId.php',
        'Stat' => __DIR__ .'/Stat.php',
        'ByggIdentListe' => __DIR__ .'/ByggIdentListe.php',
        'ByggIdent' => __DIR__ .'/ByggIdent.php',
        'MatrikkelnummerListe' => __DIR__ .'/MatrikkelnummerListe.php',
        'Matrikkelnummer' => __DIR__ .'/Matrikkelnummer.php',
        'Saksnoekkel' => __DIR__ .'/Saksnoekkel.php',
        'SaksnummerListe' => __DIR__ .'/SaksnummerListe.php',
        'Saksnummer' => __DIR__ .'/Saksnummer.php',
        'ApplicationFault' => __DIR__ .'/ApplicationFault.php',
        'ArkivKontekst' => __DIR__ .'/ArkivKontekst.php',
        'FinderFault' => __DIR__ .'/FinderFault.php',
        'GeointegrasjonFault' => __DIR__ .'/GeointegrasjonFault.php',
        'ImplementationFault' => __DIR__ .'/ImplementationFault.php',
        'Kontekst' => __DIR__ .'/Kontekst.php',
        'MatrikkelKontekst' => __DIR__ .'/MatrikkelKontekst.php',
        'OperationalFault' => __DIR__ .'/OperationalFault.php',
        'PlanKontekst' => __DIR__ .'/PlanKontekst.php',
        'StringListe' => __DIR__ .'/StringListe.php',
        'SystemFault' => __DIR__ .'/SystemFault.php',
        'ValidationFault' => __DIR__ .'/ValidationFault.php',
        'EnkelAdressetypeListe' => __DIR__ .'/EnkelAdressetypeListe.php',
        'EnkelAdressetype' => __DIR__ .'/EnkelAdressetype.php',
        'LandkodeListe' => __DIR__ .'/LandkodeListe.php',
        'Landkode' => __DIR__ .'/Landkode.php',
        'ElektroniskAdresseListe' => __DIR__ .'/ElektroniskAdresseListe.php',
        'ElektroniskAdresse' => __DIR__ .'/ElektroniskAdresse.php',
        'EnkelAdresseListe' => __DIR__ .'/EnkelAdresseListe.php',
        'EnkelAdresse' => __DIR__ .'/EnkelAdresse.php',
        'Epost' => __DIR__ .'/Epost.php',
        'Faks' => __DIR__ .'/Faks.php',
        'PostadministrativeOmraaderListe' => __DIR__ .'/PostadministrativeOmraaderListe.php',
        'PostadministrativeOmraader' => __DIR__ .'/PostadministrativeOmraader.php',
        'Telefon' => __DIR__ .'/Telefon.php',
        'Meldingsboks' => __DIR__ .'/Meldingsboks.php',
        'PersonidentifikatorTypeListe' => __DIR__ .'/PersonidentifikatorTypeListe.php',
        'PersonidentifikatorType' => __DIR__ .'/PersonidentifikatorType.php',
        'KontaktListe' => __DIR__ .'/KontaktListe.php',
        'Kontakt' => __DIR__ .'/Kontakt.php',
        'Organisasjon' => __DIR__ .'/Organisasjon.php',
        'Person' => __DIR__ .'/Person.php',
        'PersonidentifikatorListe' => __DIR__ .'/PersonidentifikatorListe.php',
        'Personidentifikator' => __DIR__ .'/Personidentifikator.php',
        'KorrespondansepartListe' => __DIR__ .'/KorrespondansepartListe.php',
        'Korrespondansepart' => __DIR__ .'/Korrespondansepart.php',
        'SaksmappeListe' => __DIR__ .'/SaksmappeListe.php',
        'Saksmappe' => __DIR__ .'/Saksmappe.php',
        'Dokumentnummer' => __DIR__ .'/Dokumentnummer.php',
        'EksternNoekkelListe' => __DIR__ .'/EksternNoekkelListe.php',
        'EksternNoekkel' => __DIR__ .'/EksternNoekkel.php',
        'JournalpostListe' => __DIR__ .'/JournalpostListe.php',
        'Journalpost' => __DIR__ .'/Journalpost.php',
        'Journpostnoekkel' => __DIR__ .'/Journpostnoekkel.php',
        'KlasseListe' => __DIR__ .'/KlasseListe.php',
        'Klasse' => __DIR__ .'/Klasse.php',
        'JournalnummerListe' => __DIR__ .'/JournalnummerListe.php',
        'Journalnummer' => __DIR__ .'/Journalnummer.php',
        'SakspartListe' => __DIR__ .'/SakspartListe.php',
        'Sakspart' => __DIR__ .'/Sakspart.php',
        'SakspartRolleListe' => __DIR__ .'/SakspartRolleListe.php',
        'SakspartRolle' => __DIR__ .'/SakspartRolle.php',
        'TilleggsinformasjonListe' => __DIR__ .'/TilleggsinformasjonListe.php',
        'Tilleggsinformasjon' => __DIR__ .'/Tilleggsinformasjon.php',
        'ArkivdelListe' => __DIR__ .'/ArkivdelListe.php',
        'Arkivdel' => __DIR__ .'/Arkivdel.php',
        'AvskrivningListe' => __DIR__ .'/AvskrivningListe.php',
        'Avskrivning' => __DIR__ .'/Avskrivning.php',
        'AvskrivningsmaateListe' => __DIR__ .'/AvskrivningsmaateListe.php',
        'Avskrivningsmaate' => __DIR__ .'/Avskrivningsmaate.php',
        'DokumentmediumListe' => __DIR__ .'/DokumentmediumListe.php',
        'Dokumentmedium' => __DIR__ .'/Dokumentmedium.php',
        'ForsendelsesmaateListe' => __DIR__ .'/ForsendelsesmaateListe.php',
        'Forsendelsesmaate' => __DIR__ .'/Forsendelsesmaate.php',
        'InformasjonstypeListe' => __DIR__ .'/InformasjonstypeListe.php',
        'Informasjonstype' => __DIR__ .'/Informasjonstype.php',
        'JournalenhetListe' => __DIR__ .'/JournalenhetListe.php',
        'Journalenhet' => __DIR__ .'/Journalenhet.php',
        'JournalposttypeListe' => __DIR__ .'/JournalposttypeListe.php',
        'Journalposttype' => __DIR__ .'/Journalposttype.php',
        'JournalstatusListe' => __DIR__ .'/JournalstatusListe.php',
        'Journalstatus' => __DIR__ .'/Journalstatus.php',
        'JournpostEksternNoekkel' => __DIR__ .'/JournpostEksternNoekkel.php',
        'JournpostSystemID' => __DIR__ .'/JournpostSystemID.php',
        'KassasjonsvedtakListe' => __DIR__ .'/KassasjonsvedtakListe.php',
        'Kassasjonsvedtak' => __DIR__ .'/Kassasjonsvedtak.php',
        'KlassifikasjonssystemListe' => __DIR__ .'/KlassifikasjonssystemListe.php',
        'Klassifikasjonssystem' => __DIR__ .'/Klassifikasjonssystem.php',
        'KorrespondanseparttypeListe' => __DIR__ .'/KorrespondanseparttypeListe.php',
        'Korrespondanseparttype' => __DIR__ .'/Korrespondanseparttype.php',
        'MappetypeListe' => __DIR__ .'/MappetypeListe.php',
        'Mappetype' => __DIR__ .'/Mappetype.php',
        'MerknadListe' => __DIR__ .'/MerknadListe.php',
        'Merknad' => __DIR__ .'/Merknad.php',
        'SakEksternNoekkel' => __DIR__ .'/SakEksternNoekkel.php',
        'SaksstatusListe' => __DIR__ .'/SaksstatusListe.php',
        'Saksstatus' => __DIR__ .'/Saksstatus.php',
        'SakSystemIdListe' => __DIR__ .'/SakSystemIdListe.php',
        'SakSystemId' => __DIR__ .'/SakSystemId.php',
        'SkjermingListe' => __DIR__ .'/SkjermingListe.php',
        'Skjerming' => __DIR__ .'/Skjerming.php',
        'SkjermingOpphorerAksjonListe' => __DIR__ .'/SkjermingOpphorerAksjonListe.php',
        'SkjermingOpphorerAksjon' => __DIR__ .'/SkjermingOpphorerAksjon.php',
        'SkjermingsHjemmel' => __DIR__ .'/SkjermingsHjemmel.php',
        'SystemIDListe' => __DIR__ .'/SystemIDListe.php',
        'SystemID' => __DIR__ .'/SystemID.php',
        'TilgangsrestriksjonListe' => __DIR__ .'/TilgangsrestriksjonListe.php',
        'Tilgangsrestriksjon' => __DIR__ .'/Tilgangsrestriksjon.php',
        'NySaksmappe' => __DIR__ .'/NySaksmappe.php',
        'NySaksmappeResponse' => __DIR__ .'/NySaksmappeResponse.php',
        'OppdaterMappeStatus' => __DIR__ .'/OppdaterMappeStatus.php',
        'OppdaterMappeStatusResponse' => __DIR__ .'/OppdaterMappeStatusResponse.php',
        'OppdaterMappeEksternNoekkel' => __DIR__ .'/OppdaterMappeEksternNoekkel.php',
        'OppdaterMappeEksternNoekkelResponse' => __DIR__ .'/OppdaterMappeEksternNoekkelResponse.php',
        'OppdaterMappeAnsvarlig' => __DIR__ .'/OppdaterMappeAnsvarlig.php',
        'OppdaterMappeAnsvarligResponse' => __DIR__ .'/OppdaterMappeAnsvarligResponse.php',
        'NyMatrikkelnummer' => __DIR__ .'/NyMatrikkelnummer.php',
        'NyMatrikkelnummerResponse' => __DIR__ .'/NyMatrikkelnummerResponse.php',
        'SlettMatrikkelnummer' => __DIR__ .'/SlettMatrikkelnummer.php',
        'SlettMatrikkelnummerResponse' => __DIR__ .'/SlettMatrikkelnummerResponse.php',
        'NyBygning' => __DIR__ .'/NyBygning.php',
        'NyBygningResponse' => __DIR__ .'/NyBygningResponse.php',
        'SlettBygning' => __DIR__ .'/SlettBygning.php',
        'SlettBygningResponse' => __DIR__ .'/SlettBygningResponse.php',
        'NyPunkt' => __DIR__ .'/NyPunkt.php',
        'NyPunktResponse' => __DIR__ .'/NyPunktResponse.php',
        'SlettPunkt' => __DIR__ .'/SlettPunkt.php',
        'SlettPunktResponse' => __DIR__ .'/SlettPunktResponse.php',
        'NySakspart' => __DIR__ .'/NySakspart.php',
        'NySakspartResponse' => __DIR__ .'/NySakspartResponse.php',
        'SlettSakspart' => __DIR__ .'/SlettSakspart.php',
        'SlettSakspartResponse' => __DIR__ .'/SlettSakspartResponse.php',
        'OppdaterPlan' => __DIR__ .'/OppdaterPlan.php',
        'OppdaterPlanResponse' => __DIR__ .'/OppdaterPlanResponse.php',
        'FinnJournalpostRestanser' => __DIR__ .'/FinnJournalpostRestanser.php',
        'FinnJournalpostRestanserResponse' => __DIR__ .'/FinnJournalpostRestanserResponse.php',
        'FinnJournalposterUnderArbeid' => __DIR__ .'/FinnJournalposterUnderArbeid.php',
        'FinnJournalposterUnderArbeidResponse' => __DIR__ .'/FinnJournalposterUnderArbeidResponse.php',
        'NyJournalpost' => __DIR__ .'/NyJournalpost.php',
        'NyJournalpostResponse' => __DIR__ .'/NyJournalpostResponse.php',
        'OppdaterJournalpostAnsvarlig' => __DIR__ .'/OppdaterJournalpostAnsvarlig.php',
        'OppdaterJournalpostAnsvarligResponse' => __DIR__ .'/OppdaterJournalpostAnsvarligResponse.php',
        'NyKorrespondansepart' => __DIR__ .'/NyKorrespondansepart.php',
        'NyKorrespondansepartResponse' => __DIR__ .'/NyKorrespondansepartResponse.php',
        'SlettKorrespondansepart' => __DIR__ .'/SlettKorrespondansepart.php',
        'SlettKorrespondansepartResponse' => __DIR__ .'/SlettKorrespondansepartResponse.php',
        'NyDokument' => __DIR__ .'/NyDokument.php',
        'NyDokumentResponse' => __DIR__ .'/NyDokumentResponse.php',
        'OppdaterJournalpostEksternNoekkel' => __DIR__ .'/OppdaterJournalpostEksternNoekkel.php',
        'OppdaterJournalpostEksternNoekkelResponse' => __DIR__ .'/OppdaterJournalpostEksternNoekkelResponse.php',
        'OppdaterJournalpostStatus' => __DIR__ .'/OppdaterJournalpostStatus.php',
        'OppdaterJournalpostStatusResponse' => __DIR__ .'/OppdaterJournalpostStatusResponse.php',
        'NyAvskrivning' => __DIR__ .'/NyAvskrivning.php',
        'NyAvskrivningResponse' => __DIR__ .'/NyAvskrivningResponse.php',
        'SlettAvskrivning' => __DIR__ .'/SlettAvskrivning.php',
        'SlettAvskrivningResponse' => __DIR__ .'/SlettAvskrivningResponse.php',
        'NyJournalpostMerknad' => __DIR__ .'/NyJournalpostMerknad.php',
        'NyJournalpostMerknadResponse' => __DIR__ .'/NyJournalpostMerknadResponse.php',
        'NyJournalpostTilleggsinformasjon' => __DIR__ .'/NyJournalpostTilleggsinformasjon.php',
        'NyJournalpostTilleggsinformasjonResponse' => __DIR__ .'/NyJournalpostTilleggsinformasjonResponse.php',
        'NySaksmappeMerknad' => __DIR__ .'/NySaksmappeMerknad.php',
        'NySaksmappeMerknadResponse' => __DIR__ .'/NySaksmappeMerknadResponse.php',
        'NySaksmappeTilleggsinformasjon' => __DIR__ .'/NySaksmappeTilleggsinformasjon.php',
        'NySaksmappeTilleggsinformasjonResponse' => __DIR__ .'/NySaksmappeTilleggsinformasjonResponse.php',
        'SlettJournalpostMerknad' => __DIR__ .'/SlettJournalpostMerknad.php',
        'SlettJournalpostMerknadResponse' => __DIR__ .'/SlettJournalpostMerknadResponse.php',
        'SlettJournalpostTilleggsinformasjon' => __DIR__ .'/SlettJournalpostTilleggsinformasjon.php',
        'SlettJournalpostTilleggsinformasjonResponse' => __DIR__ .'/SlettJournalpostTilleggsinformasjonResponse.php',
        'SlettSaksmappeMerknad' => __DIR__ .'/SlettSaksmappeMerknad.php',
        'SlettSaksmappeMerknadResponse' => __DIR__ .'/SlettSaksmappeMerknadResponse.php',
        'SlettSaksmappeTilleggsinformasjon' => __DIR__ .'/SlettSaksmappeTilleggsinformasjon.php',
        'SlettSaksmappeTilleggsinformasjonResponse' => __DIR__ .'/SlettSaksmappeTilleggsinformasjonResponse.php',
        'SystemFaultInfo' => __DIR__ .'/SystemFaultInfo.php',
        'ImplementationFaultInfo' => __DIR__ .'/ImplementationFaultInfo.php',
        'OperationalFaultInfo' => __DIR__ .'/OperationalFaultInfo.php',
        'ApplicationFaultInfo' => __DIR__ .'/ApplicationFaultInfo.php',
        'FinderFaultInfo' => __DIR__ .'/FinderFaultInfo.php',
        'ValidationFaultInfo' => __DIR__ .'/ValidationFaultInfo.php'
    );
    if (!empty($classes[$class])) {
        include $classes[$class];
    };
}

spl_autoload_register('autoload_0e0f1a3209be4bb8fba2a53011be8bbb');

// Do nothing. The rest is just leftovers from the code generation.
{
}