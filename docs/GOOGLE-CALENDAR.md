# Google Calendar instellen — versie 0.15.0

Deze eerste koppeling ondersteunt één Google-agenda per bedrijf. De database-update wordt automatisch en zonder bestaande gegevens te wissen uitgevoerd. Bestaande accounts zonder Google-koppeling blijven normaal werken.

## 1. Google Cloud (eenmalig voor Afspraakboeken.be)

1. Maak een Google Cloud-project voor Afspraakboeken.be en activeer de **Google Calendar API**.
2. Configureer Google Auth Platform: branding, supportadres, doelgroep External, contactgegevens en geautoriseerde domein `afspraakboeken.be`. Voor productie zijn een publieke privacyverklaring en de gevraagde domeinverificatie nodig.
3. Voeg deze scopes toe onder Data Access:
   - `https://www.googleapis.com/auth/calendar.events`
   - `https://www.googleapis.com/auth/calendar.calendarlist.readonly`
4. Maak een OAuth-client van type **Web application**. Voeg exact deze Authorized redirect URI toe:

   `https://app.afspraakboeken.be/api/index.php?route=calendar/callback`

5. Voeg voor de eerste test je eigen Google-account toe als testgebruiker. In de externe testmodus verlopen refresh tokens voor deze scopes doorgaans na 7 dagen. Voor blijvend gebruik door klanten moet de app naar productie en moet het toepasselijke Google-verificatieproces worden afgerond. 'In production' zetten alleen is geen garantie op Google-goedkeuring.

Officiële documentatie:
- https://developers.google.com/workspace/guides/create-credentials
- https://developers.google.com/identity/protocols/oauth2/web-server
- https://developers.google.com/identity/protocols/oauth2#expiration
- https://developers.google.com/identity/protocols/oauth2/production-readiness/sensitive-scope-verification

## 2. Hostinger-configuratie

Voeg dit toe als een **apart blok naast** `mail`, `db`, enz. in de bestaande privéconfiguratie. Behoud bestaande instellingen en de PHP-beveiligingscontrole. Zet de echte waarden uitsluitend op Hostinger, nooit in GitHub of een bericht.

```php
'google_calendar' => [
    'enabled' => true,
    'client_id' => 'JE_GOOGLE_CLIENT_ID',
    'client_secret' => 'JE_GOOGLE_CLIENT_SECRET',
],
```

`app_url` moet `https://app.afspraakboeken.be` zijn. PHP heeft OpenSSL, PDO MySQL, uitgaande HTTPS en `allow_url_fopen` nodig. Toegangstokens worden versleuteld in de database met de bestaande `app_key`. Verander die sleutel niet zonder migratie: anders moeten de Google-verbindingen opnieuw worden gelegd.

## 3. Extra cronjob

Maak naast de bestaande mail/trial-cron een aparte **PHP-cronjob** voor het echte absolute pad naar:

`api/cron-calendar.php`

Stel die bij voorkeur **elke minuut** in (`* * * * *`), of elke 5 minuten als je hosting dit vereist. Gebruik dezelfde PHP-uitvoering en configuratielocatie als de bestaande werkende cron, maar met dit nieuwe script. Het concrete pad hangt af van de map waarin Hostinger de app heeft geïmplementeerd; kopieer niet blind het pad van het hoofddomein.

Het script werkt alleen via CLI, niet via een publiek URL-verzoek. In de app toont **Agenda > Google Calendar** de laatste automatische controle, synchronisatie en wachtende updates. Een croncontrole is globaal; controleer ook de laatste beschikbaarheidscontrole van het betreffende bedrijf.

## 4. Eerste praktijktest

1. Gebruik een eigen testbedrijf en een aparte testagenda in tijdzone Brussel, Amsterdam of Parijs.
2. Open **Agenda > Google-agenda koppelen**, meld aan bij Google en geef de gevraagde rechten.
3. Selecteer de agenda en bevestig het synchroniseren van komende afspraken en geblokkeerde dagen.
4. Maak een bezet moment rechtstreeks in Google: dat moment mag niet boekbaar zijn. Test ook een hele dag en een terugkerende afspraak.
5. Boek via de widget: het item moet in Google verschijnen, inclusief ingestelde buffer.
6. Gebruik **Afspraak verplaatsen** in de app en annuleer daarna: Google moet volgen zonder duplicaten.
7. Blokkeer/deblokkeer een dag via Beschikbaarheid en controleer Google.
8. Laat minstens één croncyclus lopen. Controleer de tijden en eventuele foutmelding in de app.

De automatische tests gebruiken een gesimuleerde Google-server. Echte OAuth-, hosting- en Google-agendatoegang moet met bovenstaande praktijktest worden bevestigd voordat je dit bij klanten activeert.

## Werking en grenzen

- De app beheert afspraken; Google levert externe bezette tijd. Verplaatsen of verwijderen in Google verandert de afspraak in de app **niet**; de app kan het eigen item herstellen. Bewerk afspraken dus in Afspraakboeken.be.
- Alle externe bezette items blokkeren beschikbaarheid. Transparante items en door de agenda-eigenaar geweigerde uitnodigingen blokkeren niet. Externe titels, omschrijvingen en deelnemers worden niet in de database opgeslagen.
- Google ontvangt voor app-afspraken dienst, naam, e-mail, telefoon, adres en duur/buffer. Interne notities en klantnotities worden niet meegestuurd. Er worden geen Google-uitnodigingen verzonden; automatische afspraakbevestigingsmails zijn een afzonderlijke, nog niet aangesloten functie.
- Synchronisatie is periodiek, met maximaal 60 seconden cache voor het tonen van beschikbare tijden. Vlak vóór een boeking/verplaatsing wordt Google opnieuw gecontroleerd. Er zijn geen webhooks en geen volledige transactie over beide systemen: een exact gelijktijdige externe wijziging blijft mogelijk.
- Bij een mislukte beschikbaarheidscontrole of verzendfout worden nieuwe boekingen tegengehouden totdat de koppeling hersteld is. Reeds opgeslagen afspraken blijven bestaan; verzendopdrachten blijven bewaard en worden met een vaste externe ID opnieuw geprobeerd.
- De horizon is 60 dagen voor boekingen en 62 dagen voor externe bezette tijd. De pilot ondersteunt Brussel/Amsterdam/Parijs. Outlook en meerdere agenda's per bedrijf volgen later.
- De cron verwerkt maximaal 100 actieve bedrijven en 20 wachtende wijzigingen per bedrijf per run. Voor grotere aantallen zijn verdere capaciteitstests en queue-optimalisatie nodig.
- Ontkoppelen verwijdert lokale tokens, cache en openstaande aanmeldpogingen. Bestaande Google-items blijven staan en worden niet meer bijgewerkt. De Google-toestemming wordt niet globaal ingetrokken, omdat hetzelfde Google-account meerdere bedrijven kan beheren. Dat kan apart via de beveiligingsinstellingen van het Google-account, maar raakt dan mogelijk meerdere koppelingen.
- Eén Google-agenda kan aan één bedrijf gekoppeld worden. Bij opnieuw verbinden met dezelfde agenda blijven de externe ID's bewaard om duplicaten te voorkomen.
