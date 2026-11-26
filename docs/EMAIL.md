# Welkomstmails en meldingen van aanvragen

De app verstuurt de volgende e-mails via één SMTP-mailbox:

- **Welkomstmail:** automatisch nadat jij een klantaccount aanmaakt. De ontvanger is de opgeslagen accountbeheerder. De mail bevat een persoonlijke, eenmalige wachtwoordlink die 24 uur geldig is. Het account blijft inactief totdat jij het activeert.
- **Nieuwe aanvraag:** na het opslaan van een informatie- of activatieaanvraag, naar **kenny@kdesign.be**. Antwoorden op de melding gaat naar de aanvrager. Alle aanvragen staan ook onder **Aanvragen**; een mailstoring verwijdert ze niet.

Dit zijn transactionele mails. Er wordt geen nieuwsbriefinschrijving gemaakt. Boekingsbevestigingen zijn een afzonderlijke functie. Afspraakherinneringen worden beheerd via **Herinneringen**; zie [APPOINTMENT-REMINDERS.md](APPOINTMENT-REMINDERS.md).

## Eenmalig de verzendmailbox koppelen

Gebruik een bestaande mailbox bij Hostinger of een andere SMTP-provider. Het adres waarvandaan je verstuurt mag verschillen van `kenny@kdesign.be`, dat de meldingen ontvangt. Gebruik het afzenderadres dat jouw mailprovider toestaat.

Open op Hostinger je **bestaande** `public_html/api/private/config.php` (of je externe configuratiebestand). Voeg het onderstaande blok toe **binnen** de bestaande `return [...]`, vóór de laatste `];`. Behoud de bestaande databasegegevens, sleutels en PHP-beveiligingscontrole. Vervang je ingevulde configuratie niet door het voorbeeldbestand.

```php
    'mail' => [
        'enabled' => true,
        'host' => 'smtp.hostinger.com',
        'port' => 465,
        'encryption' => 'ssl',
        'username' => 'VUL_JE_VOLLEDIGE_MAILBOXADRES_IN',
        'password' => 'VUL_HET_MAILBOXWACHTWOORD_IN',
        'from_email' => 'VUL_JE_AFZENDERADRES_IN',
        'from_name' => 'Afspraakboeken.be',
        'reply_to' => 'kenny@kdesign.be',
    ],
```

De getoonde server en poort horen bij **Hostinger Email**. Bij een andere provider gebruik je diens SMTP-server, poort en mailboxgegevens. Alleen versleutelde verbindingen worden ondersteund: `ssl` voor impliciete TLS (meestal 465), of `tls` voor STARTTLS (meestal 587). Certificaatcontrole blijft aan. Het mailboxwachtwoord is niet je Hostinger-login of databasewachtwoord. Zet sleutels en wachtwoorden uitsluitend op de hosting, niet in ChatGPT of GitHub.

Hostinger-instructie: https://www.hostinger.com/support/1575756-how-to-get-email-account-configuration-details-for-hostinger-email/

## Controleren en opnieuw versturen

1. Vernieuw de app en open **Administratie** bij een testklant waarvan jij de mailbox beheert.
2. Controleer **E-mailadres account**. Klik op **Welkomstmail versturen**. Dat genereert een nieuwe 24-uurslink en maakt eerdere ongebruikte links ongeldig. Een bestaand wachtwoord verandert pas wanneer de ontvanger zelf een nieuw wachtwoord instelt.
3. De app meldt of de SMTP-server de mail heeft geaccepteerd. Controleer daarna de inbox en spammap. Acceptatie door de mailserver garandeert niet dat het bericht in de inbox belandt; volg voor domeinauthenticatie de instructies van je mailprovider.
4. Doe een herkenbare testaanvraag op `/registreren/`. Controleer zowel **Aanvragen** als de mailbox `kenny@kdesign.be`.

Zonder SMTP-instellingen wordt een klantaccount wel opgeslagen en krijg je een kopieerbare activatielink. Bij een verzendfout gebeurt hetzelfde. Er wordt nooit ten onrechte gemeld dat een mail is verstuurd. Voor welkomstmails zijn er geen automatische herhaalpogingen. Gebruik de knop in Administratie voor een bestaande klant. Meldingen over nieuwe bedrijfsaanvragen en boekingen hebben vanaf 0.16.1 wel automatische herhaalpogingen, zoals hieronder beschreven.

**E-mailadres voor facturen** is apart bewerkbaar in Administratie. Dit wijzigt het aanmeldadres en de ontvanger van de welkomstmail niet. Facturatie blijft buiten de app.

## Aanvraagpagina

De informatieroute `/registreren/` verzamelt naam, bedrijf, e-mailadres en sector. Telefoon en toelichting zijn optioneel. Het formulier maakt geen gebruikersaccount of abonnement aan. Na opslaan in de database krijgt de bezoeker een bevestiging op het scherm en komt de aanvraag in de superadmininbox. De database wordt opgeslagen vóór het versturen van de melding.

De indeling gebruikt een kort formulier met één duidelijke actie, een aparte link voor bestaande gebruikers en een uitleg van de volgende stap. Referenties: https://calendly.com/signup en https://slack.com/intl/en-gb/contact-sales . De tekst en vormgeving zijn voor Afspraakboeken.be gemaakt; er worden geen fictieve beoordelingen, klantenaantallen of gratis proefperiodes getoond.


Proefaccounts gebruiken dezelfde SMTP-koppeling voor e-mailbevestiging en herinneringen. Zie [TRIALS.md](TRIALS.md) voor de aparte proefaccountflow en de vereiste crontaak.


## Nieuwe aanvraag of boeking bij een bedrijf (0.16.1)

Een nieuwe openbare widgetboeking maakt tegelijk met de afspraak een unieke melding in `ab_company_notifications`. Losse openbare aanvragen gebruiken dezelfde wachtrij. Na opslaan probeert de app onmiddellijk te verzenden via de bestaande SMTP-configuratie. De ontvanger is het bedrijfsaccountadres (`contact_email`), nooit een ontvanger uit het publieke formulier, het facturatieadres of de agency-inbox.

De onderwerpregel is **Nieuwe lead: afspraak geboekt** of **Nieuwe lead: aanvraag ontvangen**, met Afspraakboeken.be als afzendernaam. De HTML- en tekstversies bevatten naam, e-mail, telefoon, dienst, eventueel adres en klantbericht; boekingen bevatten ook datum, tijd (Belgische tijd) en duur. Interne CRM-notities worden niet verstuurd. Beantwoorden gaat naar de eindklant. De knop opent Afspraken of Klanten opvolgen na aanmelden.

De bestaande `api/cron-trials.php` verwerkt voortaan ook maximaal 50 wachtende bedrijfsmeldingen per run. Geen nieuwe cronjob nodig. Na een bekende verzendfout wordt ten vroegste 15 minuten later opnieuw geprobeerd, bij de volgende cronrun, met maximaal 8 pogingen. Een afgebroken proces met onduidelijke verzendstatus wordt niet automatisch opnieuw verstuurd om duplicaten te vermijden. De e-mailinstellingen tonen het ontvangstadres, wachtende meldingen en meldingen die aandacht nodig hebben.

Herhaalde formulierverzoeken gebruiken dezelfde melding; een verzonden melding wordt niet opnieuw verstuurd. Een vaste Message-ID helpt bij herkenning van herhaalpogingen. SMTP kan geen absolute exactly-once inboxbezorging garanderen wanneer de verbinding wegvalt nadat de ontvangende server de mail al heeft geaccepteerd. SMTP-acceptatie garandeert ook geen inboxplaatsing.

Bestaande afspraken van vóór deze release worden niet achteraf gemaild. Handmatig ingevoerde leads en intern ingeplande afspraken maken geen extra melding aan de invoerende beheerder. Een geannuleerde afspraak of een gedeactiveerd bedrijf wordt bij een uitgestelde verzendpoging overgeslagen. De bevestigingsmail aan de eindklant is een afzonderlijke functie en wordt door deze update niet geactiveerd.


## Afspraakherinneringen (0.21.0)

Bedrijven kiezen zelf voor 3 dagen vooraf, 1 dag vooraf (standaard aan) en/of de dag zelf. Antwoorden gaan via Reply-To naar hun actuele contactadres, zonder extra uitleg in de mailtekst. De bestaande kalendercron verzorgt de verzending. Zie [APPOINTMENT-REMINDERS.md](APPOINTMENT-REMINDERS.md) voor planning, rechten en verificatie.

## Partneruitnodigingen (0.22.1)

De welkomstmail voor partners wordt verstuurd vanaf **hallo@afspraakboeken.be**, met een persoonlijke wachtwoordlink en een aparte link naar het partnerportaal. De bestaande SMTP-instellingen moeten voor deze afzender toestemming hebben. Richt de mailbox of geautoriseerde afzender in bij de mailprovider; bewaar authenticatiegegevens uitsluitend in de privéconfiguratie. Andere klantmails blijven hun bestaande afzender gebruiken.

Superadmin ziet de laatste verzendstatus en een veilige foutcategorie bij **Partners → Beheren → Partnergebruikers**. Een fout bij SMTP-aanmelding vraagt om controle van mailboxgebruikersnaam/wachtwoord; een geweigerde afzender vraagt om toestemming voor hallo@afspraakboeken.be. Bij verbindingsproblemen controleer je host, poort en versleuteling. Er worden geen SMTP-transcripten, wachtwoorden of uitnodigingstokens in foutmeldingen getoond. Een partner blijft bewaard als verzending mislukt, zodat opnieuw uitnodigen mogelijk blijft.
