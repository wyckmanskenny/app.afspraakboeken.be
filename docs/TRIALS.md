# Instroom en gratis proefperiode

## De namen en routes

| Bezoeker wil | Knop | Route en resultaat |
| --- | --- | --- |
| Eerst kijken | Bekijk de demo | Openbare demo met fictieve gegevens |
| Kosten weten | Prijzen | `https://afspraakboeken.be/prijzen/` |
| Zelf beginnen | 14 dagen gratis proberen | `/registreren/?mode=trial`, daarna e-mailbevestiging en wachtwoord kiezen |
| Eerst iets bespreken | Vraag vrijblijvend informatie op | `/registreren/`: introductie → formulier op dezelfde pagina → bevestiging |
| Verder werken | Inloggen | De bestaande accountlogin |
| Na de proefperiode doorgaan | Abonnement activeren | Inloggen → activatieverzoek met facturatiegegevens → bevestiging door superadmin |

Prijs en facturatieperiode worden per bedrijf afgesproken en door de superadmin opgeslagen bij **Administratie**. Nieuwe trials krijgen geen vast abonnementstarief. Activatie behoudt de ingevoerde prijs en formule. Deze versie ondersteunt één beheerder per bedrijf. Er is geen automatische betaling of automatische omzetting naar een betaald contract.

## Wat de superadmin ziet

- **Aanvragen:** informatievragen en verzoeken om een abonnement te activeren. Het rode bolletje telt alle aanvragen met status `Nieuw`. Openen wist niets; de status wijzigen naar `Gecontacteerd` of `Gearchiveerd` verlaagt het aantal. Het aantal wordt bij openen en elke minuut in een zichtbare superadminomgeving opgehaald.
- **Gratis trial gestart:** bevestigde proefaccounts met start, einddatum, resterende tijd, activatieverzoek, activatiedatum en status van de vier herinneringen. Onbevestigde e-mailadressen zijn nog geen proefaccounts.
- **Abonnement activeren:** expliciet bevestigen dat de formule en prijs met de klant zijn afgesproken en in Administratie zijn vastgelegd. Daarna vervalt de proefbeperking en wordt het activatieverzoek als gecontacteerd gemarkeerd. Facturatie en betalingen blijven handmatig.

## Eenmalige hostinginstellingen

1. Vul SMTP in de bestaande private configuratie in; zie [EMAIL.md](EMAIL.md). Zonder SMTP is de informatieroute beschikbaar, maar kan een automatische proefregistratie niet starten. Bestaande accounts blijven werken.
2. Open in Hostinger **Websites → Beheren → Cron Jobs**. Voeg een taak van type **PHP** toe voor het **absolute pad op jouw hosting** naar `public_html/api/cron-trials.php` van **app.afspraakboeken.be**. Neem het echte pad over uit hPanel/FTP Accounts; gebruik geen voorbeeldgebruikersnaam. Een mogelijke structuur is `/home/JOUW_HOSTINGGEBRUIKER/domains/app.afspraakboeken.be/public_html/api/cron-trials.php`, maar jouw subdomein kan anders zijn ingericht.
3. Stel de taak in op **elk uur**, bijvoorbeeld minuut `10`, uur `*`, dag `*`, maand `*`, weekdag `*` (`10 * * * *`). Hostinger plant in UTC; de code berekent de herinneringsdagen zelf in **Europe/Brussels**.
4. Controleer de uitvoer. De taak geeft alleen aantallen (`sent`, `failed`, `skipped`) of `mail_not_configured` terug, nooit adressen, tokens of wachtwoorden. In **Gratis trial gestart** verdwijnt de waarschuwing over de geplande taak zodra deze recent heeft gedraaid.

Officiële Hostinger-instructies:
- https://www.hostinger.com/support/1583465-how-to-set-up-a-cron-job-at-hostinger/
- https://www.hostinger.com/support/1583514-troubleshooting-cron-jobs-at-hostinger/

De taak werkt uitsluitend via PHP CLI. Een bezoek aan de HTTP-URL krijgt 403; er staat geen cronwachtwoord of trigger in een openbare URL. De taak leest dezelfde private configuratie als de app. Bij een afwijkend extern configuratiepad moet ook de cronomgeving `AFSPRAAKBOEKEN_CONFIG` naar dat bestaande bestand laten verwijzen.

## Levenscyclus en foutafhandeling

- E-mailbevestiging werkt één keer en is 24 uur geldig. Het wachtwoord wordt pas na bevestiging gehasht opgeslagen. Een reeds gebruikt e-mailadres krijgt geen tweede account of nieuwe proefperiode.
- De proefperiode duurt exact 14 × 24 uur vanaf bevestiging. De eindtijd staat in de app en mails in Belgische tijd.
- Op 3, 2 en 1 kalenderdag vóór de einddatum en op de einddatum stuurt de taak een herinnering. De link opent de login en daarna **Mijn abonnement**; de link geeft zelf geen toegang.
- Eén herinnering per bedrijf per mijlpaal. Een globale uitvoeringslock voorkomt parallelle taken. SMTP-fouten krijgen maximaal drie pogingen, minstens ongeveer één uur uit elkaar, op dezelfde kalenderdag. Een stilgevallen proces met status `sending` wordt niet blind opnieuw verstuurd: controleer dan de mailserver, zodat een al geaccepteerde mail niet dubbel wordt verstuurd. Geen inhaalstapel van herinneringen uit eerdere dagen.
- Per uitvoering worden maximaal 50 verzendpogingen gedaan. Controleer bij grotere volumes de cronfrequentie en limieten van de mailboxprovider.
- Na conversie worden volgende herinneringen overgeslagen. Bij afloop stoppen nieuwe boekingen en wijzigingen direct via servercontrole, ook als de crontaak niet loopt. De klant kan nog inloggen voor activatie; gegevens worden niet gewist.
- Een activatieverzoek is ontdubbeld. Herhaald klikken maakt geen extra aanvraag of e-mail. Het facturatieadres wijzigt het aanmeldadres niet.

## Database-update en controle

De eerste API-aanroep na de update maakt uitsluitend drie aanvullende tabellen met `CREATE TABLE IF NOT EXISTS`: `ab_trial_signups`, `ab_trials` en `ab_trial_mail`. Een databaselock en auditmarkering maken herhalen veilig. Er zijn geen wijzigingen aan bestaande kolommen en geen omzetting van bestaande klanten. De databasegebruiker moet tabellen mogen aanmaken, zoals bij de oorspronkelijke installatie.

Verificatie: de lokale integratietests gebruiken een wegwerpdatabase en een lokale TLS-mailserver. Ze testen bevestiging, eenmalige tokens, 14 dagen toegang, rolgrenzen, afloop zonder cron, vier herinneringen zonder duplicaten, activatieverzoeken en handmatige conversie. Controleer na de hostinginstellingen ook met een eigen testmailbox of de echte mails aankomen. Er zijn tijdens ontwikkeling geen productieaccounts of proefaanvragen aangemaakt.
