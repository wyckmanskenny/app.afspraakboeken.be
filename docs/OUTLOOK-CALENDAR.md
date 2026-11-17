# Microsoft Outlook agenda koppelen

De app ondersteunt één externe agenda per bedrijf: Google Calendar **of** Microsoft Outlook. Outlook staat uit totdat de onderstaande privéconfiguratie is ingevuld. Bestaande Google-koppelingen blijven behouden.

## Eenmalig instellen bij Microsoft

1. Open https://entra.microsoft.com/ en ga naar **App registrations > New registration**.
2. Naam: **Afspraakboeken.be**.
3. Supported account types: **Accounts in any organizational directory and personal Microsoft accounts**. Zo kunnen zakelijke Microsoft 365-accounts en persoonlijke Outlook.com-accounts aanmelden.
4. Voeg bij platform **Web** exact deze redirect URI toe:
   `https://app.afspraakboeken.be/api/outlook-callback.php`
   Gebruik geen Desktop- of Single-page application-client. Deze callback bevat bewust geen queryparameters voor persoonlijke Microsoft-accounts.
5. Ga naar **API permissions > Add a permission > Microsoft Graph > Delegated permissions**. Voeg **Calendars.ReadWrite** en **offline_access** toe. De app vraagt geen application permissions en heeft geen toegang tot mailboxinhoud nodig. Verwijder standaardrechten die je niet gebruikt als deze registratie uitsluitend voor deze koppeling dient.
6. Ga naar **Certificates & secrets > New client secret**. Kopieer de **Value**, niet de Secret ID, en noteer de vervaldatum. Houd deze geheim.
7. Vul Application (client) ID en secret in de bestaande privé-config.php op Hostinger in, naast `google_calendar` en `mail`:

```php
'outlook_calendar' => [
    'enabled' => true,
    'client_id' => 'JOUW_APPLICATION_CLIENT_ID',
    'client_secret' => 'JOUW_CLIENT_SECRET_VALUE',
],
```

Zet secrets nooit in GitHub of publieke bestanden. Verander de bestaande `app_key` niet; deze beschermt de opgeslagen verbindingstokens. De bestaande cronjob voor `api/cron-calendar.php` verwerkt voortaan beide aanbieders; maak geen dubbele cronjob aan.

## Testen voor klanten

Open **Agenda > Koppel je agenda > Microsoft Outlook**, meld aan met een testaccount en selecteer een beschrijfbare agenda. Test met zowel een persoonlijk als een zakelijk account als je beide aanbiedt. Sommige bedrijven vereisen toestemming van hun Microsoft-beheerder. Voor bredere uitrol: controleer app-branding, privacyverklaring en publisher verification bij Microsoft.

Controleer een bezet tijdvak, een herhalende afspraak, een hele dag, een nieuwe testboeking, verplaatsen en annuleren. Gebruik fictieve klantgegevens. Controleer de foutmelding en het weigeren van nieuwe boekingen wanneer de externe agenda niet beschikbaar is. Controleer ook cronverwerking na sluiten van de browser. De geautomatiseerde tests gebruiken een gesimuleerde Microsoft API; een echte Microsoft-acceptatietest blijft nodig na configuratie.

## Werking en grenzen

- De app leest een voortschrijdend venster van 62 dagen via Graph calendarView (inclusief herhalingen). Bezet, voorlopig en afwezig blokkeren; vrije, geweigerde en geannuleerde afspraken niet.
- Beschikbaarheid wordt periodiek ververst en vlak voor een boeking opnieuw gecontroleerd. Zonder betrouwbare externe beschikbaarheid worden nieuwe boekingen geweigerd.
- Afspraken, buffers en geblokkeerde dagen verschijnen in Outlook. Beheer deze items in Afspraakboeken.be. Aanpassingen in Outlook worden niet als lokale wijzigingen geïmporteerd; de app kan haar eigen items terugzetten.
- De eerste versie werkt met bedrijfstijden Europe/Brussels. Geblokkeerde dagen worden als hele dagen in de overeenkomstige Microsoft-tijdzone geschreven.
- Er worden geen deelnemers toegevoegd, dus geen Microsoft-uitnodigingen verstuurd. Naam, dienst, contactgegevens en adres worden meegestuurd; interne notities en klantberichten niet.
- Versleutelde tokens, sessiegebonden eenmalige OAuth-state en PKCE; tenantcontrole en wederzijdse uitsluiting van Google/Outlook.
- Stabiele transactionId en eigen extended property voorkomen duplicaten bij een verloren aanmaakantwoord. Bestaande externe items worden alleen aangepast als ze aantoonbaar van deze app en dit bedrijf zijn; items met toegevoegde deelnemers vereisen handmatige controle.
- Ontkoppelen laat bestaande Outlook-items staan en stopt het blokkeren door Outlook. De database-update gebeurt automatisch.

## Technische controle

`tests/outlook-integration.php` gebruikt uitsluitend de lokale disposable testdatabase en een mock-transport. `tests/ui-smoke.mjs` controleert providerkeuze en bestaande Google-stromen. Voor de CLI-integratietest zijn dezelfde PHP/PDO-instellingen als de Google-test nodig, met `TEST_CONFIG_PATH` naar een lokale testconfiguratie.

Documentatie: https://learn.microsoft.com/en-us/graph/api/calendar-list-calendarview?view=graph-rest-1.0 en https://learn.microsoft.com/en-us/graph/api/user-post-events?view=graph-rest-1.0
