# Afspraakherinneringen

Vanaf 0.21.0 beheren bedrijven hun herinneringen in **Herinneringen**. Dit hoort bij de basisomgeving; CRM Plus is niet nodig. Wijzigingen worden automatisch opgeslagen. Een zichtbaar foutbericht en een nieuwe poging voorkomen dat een mislukte opslag als geslaagd wordt voorgesteld.

| Herinnering | Standaard | Verzendmoment |
| --- | --- | --- |
| 3 dagen vooraf | Uit | Drie kalenderdagen voor de afspraak, op hetzelfde lokale uur |
| 1 dag vooraf | Aan | Eén kalenderdag voor de afspraak, op hetzelfde lokale uur |
| Op de dag zelf | Uit | Zelf te kiezen uur, standaard 08:00 |

Alle tijden volgen Europe/Brussels, ook bij zomer- en wintertijd. Een herinnering op de dag zelf wordt alleen ingepland als de afspraak daarna begint. De afzendernaam, bedrijfsnaam in de tekst en accentkleur volgen het bedrijf. Een eigen boodschap van maximaal 1000 tekens is optioneel. HTML in die boodschap wordt als tekst weergegeven.

## Antwoorden op de mail

De ontvanger is de eindklant bij de afspraak (`customer_email`). **Reply-To is altijd het actuele contactadres van het bedrijf (`ab_tenants.contact_email`)**, opgehaald vlak voor verzending. Het aanmeldadres, facturatieadres, adres van de eindklant of een via het formulier meegestuurd adres worden hiervoor niet gebruikt. Er staat geen uitnodiging om te antwoorden of te annuleren in de mailtekst.

De technische From-mailbox blijft de geconfigureerde SMTP-afzender, bijvoorbeeld `hallo@afspraakboeken.be`. Het bedrijf krijgt alleen de afzendernaam, zodat de bestaande domeinauthenticatie behouden blijft. De voorbeeldmail gaat uitsluitend naar het aangemelde account, ongeacht meegestuurde ontvangers. Voorbeelden zijn begrensd op 3 per gebruiker per 15 minuten en 5 per bedrijf per uur.

## Planning en verzending

De migratie maakt automatisch `ab_appointment_reminders` en plant herinneringen voor bestaande bevestigde toekomstige afspraken. Oude of reeds verstreken verzendmomenten worden niet ingehaald. Nieuwe openbare boekingen en vanuit Aanvragen ingeplande afspraken maken de wachtrij aan in dezelfde transactie als de boeking. Nieuwe herinneringen moeten nog minstens één uur in de toekomst liggen. Een instelling aanpassen geldt ook voor toekomstige afspraken; een ongewijzigde bestaande planning blijft behouden, ook vlak voor verzending.

Verplaatsen markeert de oude wachtende herinneringen als overgeslagen en plant voor het nieuwe moment. Annuleren stopt wachtende herinneringen. Verstuurde mails blijven in de historie. Iedere combinatie van afspraak, startmoment en herinnering heeft één unieke sleutel. Een opnieuw ingeschakelde herinnering wordt niet nogmaals verstuurd als die al verzonden is.

De **bestaande `api/cron-calendar.php`** verwerkt de herinneringen; laat die op Hostinger **elke vijf minuten** draaien. Er zijn geen nieuwe SMTP-waarden of extra cronjobs nodig als deze taak al actief is. E-mails worden onafhankelijk van fouten bij Google of Outlook verwerkt. De pagina toont een waarschuwing wanneer de verzendcontrole langer dan twintig minuten niet heeft gedraaid.

Per run worden maximaal 50 vervallen taken verwerkt. Bekende SMTP-fouten krijgen maximaal acht pogingen, met minstens vijftien minuten ertussen. Meer dan zes uur te late herinneringen worden overgeslagen; er gaan nooit herinneringen uit na de start van de afspraak of binnen zes uur na een andere verzonden herinnering voor dezelfde afspraak. Een inactief bedrijf verstuurt geen herinneringen. Een onderbroken worker met onduidelijke verzendstatus wordt na vijftien minuten `uncertain` en niet automatisch opnieuw verstuurd. Bekijk de status bij **Afspraakdetails → Herinneringen**. `Verzonden` betekent SMTP-acceptatie, geen garantie op inboxplaatsing.

Het verzendvoornemen wordt vóór SMTP opgeslagen. De worker controleert de actuele afspraak en instellingen opnieuw onder dezelfde bedrijfsvergrendeling als annuleren en verplaatsen. Een annulering die eerst is opgeslagen onderdrukt de verzending; een mail die al is geaccepteerd door SMTP kan niet worden teruggehaald. SMTP biedt bij een verbroken verbinding rond acceptatie geen absolute garantie tegen dubbele bezorging.

De onmiddellijke boekingsbevestiging en annuleringsmail zijn afzonderlijke functies en worden hiermee niet geactiveerd. De opgeslagen bevestigingstekst blijft bereikbaar onder **Meldingen en afspraakbevestigingen**.

## Verificatie

`tests/appointment-reminders-unit.php` controleert lokale tijden rond beide zomertijdovergangen. `tests/appointment_reminders_integration.py` draait via de bestaande SMTP-suite tegen een lokale wegwerpdatabase en TLS-testmailbox. Die controleert Reply-To en From, ontvangers, geen antwoordinstructies, veilige tekst, tenantrechten, annuleringen, verplaatsingen, gelijktijdige workers, foutafhandeling en te late taken. De frontendtests in `tests/ui-smoke.mjs` controleren autosave, fout/herstel, de geïsoleerde preview en de testmailactie. Geen echte klanten of externe mailboxen worden door deze tests gebruikt.
