# Database en eerste superadmin activeren

De code staat los van de geheime serverconfiguratie. GitHub-updates mogen die configuratie niet overschrijven. Vul wachtwoorden of sleutels nooit in een GitHub-bestand of in ChatGPT in.

## 0. Cache na publicatie

Wis na deze eerste publicatie de Hostinger-cache voor **beide websites**, en de CDN-cache als die aan staat. Tijdens de controle leverde de gewone app-URL nog de oude demo terwijl de nieuwe bestanden al op de server stonden. Via `https://app.afspraakboeken.be/?v=0.9.0` kun je de nieuwe login alvast bekijken.

De meegeleverde `.htaccess` geeft de app `Cache-Control: no-store` en laat de homepage opnieuw controleren op wijzigingen. Bestaande CDN-kopieën verdwijnen daardoor niet vanzelf onmiddellijk: die moet je eenmalig wissen. Laat de volledige app en vooral `/api/` buiten eventuele geforceerde CDN- of paginacaching. Controleer daarna in een privévenster dat de normale app-URL het aanmeldscherm toont en niet de demo.

## 1. Een database onder de app-website

Selecteer in Hostinger de website **app.afspraakboeken.be** en open **Databases → Beheer / Management**. Maak één lege MySQL-database met een eigen databasegebruiker. Gebruik de volledige namen, inclusief Hostingers accountvoorvoegsel. Bewaar het databasewachtwoord in je wachtwoordbeheerder.

Dit hoort bij een Custom PHP/HTML-site. Gebruik PHP 8.2 of nieuwer met PDO MySQL. De app heeft geen Node.js-server nodig.

Officiële instructie: https://www.hostinger.com/support/1583542-how-to-create-a-new-mysql-database-in-hostinger/

## 2. Privéconfiguratie buiten de webmap

Maak bij de app-website **naast** `public_html` een map `afspraakboeken-private`. Plaats daar een kopie van `api/config.example.php`, hernoemd naar `config.php`.

Verwachte indeling bij een afzonderlijke Hostinger-website:

- `afspraakboeken-private/config.php` — geheime configuratie, niet publiek en niet in Git
- `public_html/index.html` — login
- `public_html/api/index.php` — API
- `public_html/install/index.html` — eenmalige eerste-accountinstallatie

De standaardcode zoekt twee niveaus boven de map `api`, daarna in `afspraakboeken-private/config.php`. Controleer dat deze privémap **ook niet binnen de webmap van de hoofdwebsite** ligt. Als jouw app onder een submap van een andere publieke website staat, gebruik dan een absoluut privépad via de serveromgevingvariabele `AFSPRAAKBOEKEN_CONFIG`. Zet geen databasegegevens in `public_html`, `.htaccess`, een browserformulier of de repository.

Vul in de privéconfiguratie de databasehost, poort, volledige databasenaam, gebruiker en het wachtwoord in. Gebruik de hostnaam die Hostinger aangeeft; normaal is dit `localhost`. Laat `app_url` op `https://app.afspraakboeken.be` en `secure_cookies` op `true` staan.

Vul twee **verschillende** willekeurige sleutels van minimaal 32 tekens in bij `app_key` en `setup_key`. Je wachtwoordbeheerder kan die genereren. Heb je SSH, dan genereert dit commando één sleutel:

```sh
php -r 'echo bin2hex(random_bytes(32)), PHP_EOL;'
```

Voer het twee keer uit. Bewaar de sleutels uitsluitend privé. Beperk de bestandsrechten van de privémap en het configuratiebestand tot je hostinggebruiker (bijvoorbeeld map 700 en bestand 600, als de PHP-gebruiker daarmee kan lezen).

## 3. Jouw eerste account

Open **https://app.afspraakboeken.be/install/**. Vul je installatiesleutel, naam, e-mailadres en zelfgekozen wachtwoord in. De beveiligde installatie maakt de tabellen en precies één superadminaccount aan. Er worden geen demoaccounts of klantgegevens geïmporteerd.

Na een geslaagde installatie kun je de waarde van `setup_key` in de privéconfiguratie leegmaken. De API weigert een nieuwe installatie sowieso zodra er een superadmin bestaat.

Open vervolgens **https://app.afspraakboeken.be/** en meld je aan. Controleer dat je **Klanten beheren** en **Aanvragen** ziet. De status op `https://app.afspraakboeken.be/api/index.php?route=health` wordt `ready: true`; daarmee wordt het contactformulier op de homepage beschikbaar.

## 4. Een klant toevoegen

1. Kies **Klant toevoegen** en vul bedrijfsnaam, beheerder, e-mailadres, sector, formule, bedrag en huisstijl in.
2. Het bedrijfsaccount start inactief. Er is nog geen wachtwoord ingesteld.
3. Kopieer de persoonlijke activatielink en deel die met de juiste klant. De link is 24 uur geldig en werkt één keer. Er wordt geen e-mail automatisch verzonden.
4. Regel de factuur buiten de app. Activeer het bedrijf via **Administratie** wanneer jij klaar bent.
5. De klant kiest via de activatielink zelf een wachtwoord. Na activatie kan die alleen de eigen omgeving openen.
6. Voeg diensten en beschikbaarheid toe en test een afspraak met fictieve gegevens. Pas daarna deel je de echte boekingslink of widget.

Een nieuwe link via **Activatielink** maakt eerdere ongebruikte links ongeldig. De klant kan daarmee ook een nieuw wachtwoord instellen. Een geslaagde wachtwoordwijziging beëindigt bestaande sessies.

## 5. Controle en beheer

- Test één afspraak via een privévenster en controleer of die in het juiste bedrijfsaccount verschijnt.
- Test één contactaanvraag op de homepage en controleer die als superadmin onder **Aanvragen**.
- Meld je aan als klant: agencybeheer en de contactinbox mogen niet zichtbaar of toegankelijk zijn.
- Deactiveer een testbedrijf: bestaande sessies moeten stoppen en de boekingspagina moet sluiten; de gegevens blijven bewaard.
- Zet databaseback-ups in Hostinger aan voordat je echte gegevens gaat bewaren. Een GitHub-back-up bevat geen database-inhoud.
- Houd PHP en de hostingomgeving bij. De app maakt gebruik van PHP's wachtwoordhashfuncties en prepared SQL-statements.
- Optioneel: laat dagelijks `php /absoluut/pad/naar/public_html/api/maintenance.php` uitvoeren via een Hostinger-crontaak. Dit ruimt alleen verlopen sessies, rate-limitregels en oude activatietokens op; het verwijdert geen klanten, afspraken of contactaanvragen. Gebruik het werkelijke serverpad van jouw app.

E-mailverzending en agendasynchronisatie worden afzonderlijk aangesloten. Tot dan staat een afspraak in de database en op het scherm; er wordt geen bevestigingsmail beloofd of verstuurd.
