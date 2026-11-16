# Websitewidget: afspraken of aanvragen

Vanaf 0.11.0 beheert elke bedrijfsbeheerder dit zelf via **Websitewidget → Jouw werkwijze**. Een superadmin kan hetzelfde doen in de klantomgeving.

- **Direct boeken** (standaard): dienst → datum/uur → gegevens → bevestigde afspraak.
- **Aanvragen ontvangen**, dienstkeuze aan: dienst → gegevens/bericht → ontvangstbevestiging.
- **Aanvragen ontvangen**, dienstkeuze uit: gegevens/bericht → ontvangstbevestiging. Werkt zonder diensten.

Omschakelen wijzigt alleen nieuwe inzendingen. Bestaande afspraken en aanvragen blijven bewaard. Een al geopend formulier kan na omschakelen vragen om opnieuw te laden. Kopieer de widgetcode opnieuw als ook het knoplabel op de externe website moet wijzigen; de inhoud van de widget volgt automatisch de opgeslagen instelling. Pop-up, zijpaneel en inline gebruiken hetzelfde formulier.

## Opvolging

Aanvragen staan in **Klanten opvolgen**, met fasen Nieuw, Gecontacteerd en Afgerond. In aanvraagmodus toont het startoverzicht de laatste aanvragen. De gegevens, het bericht, de bron en interne notities staan bij de kaart. Een aanvraag kan zonder afspraak worden afgerond en weer heropend.

**Afspraak inplannen** vraagt een actieve dienst, datum en een beschikbaar tijdstip. Dezelfde uren, geblokkeerde dagen, voorbereidingstijd en duur/buffer gelden als bij online boeken, met een horizon van 60 dagen. Spreek het moment eerst met de eindklant af. Er wordt nog geen afspraakbevestigingsmail verstuurd. Een ingeplande aanvraag blijft met haar oorspronkelijke informatie gekoppeld aan precies één afspraak; het CRM toont hiervoor één kaart. Een geannuleerde gekoppelde afspraak blijft zichtbaar. Opnieuw inplannen/verplaatsen van die afspraak is nog geen onderdeel van deze versie.

De basale opvolging van aanvragen blijft beschikbaar wanneer de agency de CRM-module voor gewone boekingen uitzet. Die instelling kan de klant niet zelf aanpassen. De openbare aanvraagmodus maakt nooit een reservering aan; alleen het expliciete inplannen doet dat.

## Opslag en meldingen

`ab_leads` is een nieuwe tabel, strikt gescheiden per bedrijfsaccount. `ab_contacts` blijft de salesinbox van Afspraakboeken.be zelf. De migratie maakt alleen de nieuwe tabel aan, met een database-lock en auditmarkering `schema.leads_v1`. Bestaande tabellen worden niet vervangen of aangepast. De normale hostdatabasegebruiker heeft CREATE-rechten nodig, net als voor de eerdere trialmigratie.

Een nieuw opgeslagen verzoek probeert één melding naar het **opgeslagen contactadres van het bedrijf** te sturen, met het gevalideerde adres van de aanvrager als Reply-To. De ontvanger komt nooit uit het openbare formulier. Dit gebruikt de centrale SMTP-configuratie uit `docs/EMAIL.md`. Zonder SMTP blijven aanvragen gewoon in het CRM staan. Auditstatus: `lead.mail_sent`, `lead.mail_failed` of `lead.mail_not_configured`. Geen automatische mailherhaling in deze versie; een herhaalde inzending met dezelfde sleutel maakt geen tweede aanvraag of melding.

Aanvragen hebben een eigen idempotentiesleutel. Inplannen gebruikt dezelfde bedrijfslock als publieke reserveringen en beschikbaarheidswijzigingen. Parallelle aanvragen kunnen geen dubbel tijdslot boeken. Interne notities hebben een versienummer tegen het overschrijven van gelijktijdige wijzigingen. Verlopen trials kunnen geen aanvragen meer ontvangen of wijzigen.

## Meting

Alleen bij ingeschakelde campagnemeting én toestemming:
- `booking_completed`: een echte publieke reservering, eventueel met waarde.
- `lead_submitted`: een ontvangen aanvraag, zonder omzetwaarde.

Geen naam, e-mail, telefoon of bericht in de datalayer. De widget controleert bronvenster en origin en dedupliceert gebeurtenissen. Later intern inplannen verstuurt geen tweede publieke conversie.

## Verificatie

Lokale integratietests controleren beide rollen, tenantisolatie, dienstkeuze, contactformulier zonder diensten, mailontvangers, herhaalde inzendingen, moduswissels, trialverloop, beschikbaarheid, gelijktijdig inplannen en een race met een openbare reservering. E-mails gaan alleen naar een lokale TLS-testserver. DOM-tests controleren de drie routes, veldbehoud, bevestigingen, CRM, instelling en tracking. De beheerde visuele browserpreview was bij deze release niet beschikbaar; er zijn geen productieaccounts of productieaanvragen aangemaakt om te testen.

## CRM board (0.12.0)

Manual leads require a name and either phone or email. Optional service and address. Tenant-scoped, idempotent creation sends no email and reserves no time.

Column labels and added columns are stored per tenant in settings.crmColumns, guarded by tenant revision. Existing IDs/order stay stable, including first column new and terminal column done. Maximum 12 columns and 40 characters per label. Renaming retains all records.

Desktop drag-and-drop saves before rerendering; failure retains the original position. Dropdowns remain available for touch and keyboard. Focus view fills the viewport, closes with its button or Escape, and survives saves. Reduced-motion preferences disable animations.
