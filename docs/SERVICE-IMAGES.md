# Dienstafbeeldingen — 0.16.0

Bij **Diensten > Dienst toevoegen/bewerken** kan de beheerder een JPG, PNG of WebP kiezen, bekijken, vervangen of verwijderen. Maximaal 5 MiB (5 × 1024 × 1024 bytes) per bestand; de interface noemt dit 5 MB. Video blijft een HTTPS-link. Zonder foto, of bij een onbereikbare foto, verschijnt een neutrale standaardweergave. Afbeeldingen zijn bedoeld als publieke dienstfoto's.

Categorie staat niet meer in het formulier of op de beheerderskaart. Bestaande categorieën blijven in de database bewaard; opslaan zonder categorieveld overschrijft die gegevens niet. Nieuwe diensten hebben standaard een lege categorie.

## Opslag en hosting

- Nieuwe tabel `ab_service_images`, automatisch aangemaakt. Afbeeldingen staan als binaire data in MySQL en blijven daardoor behouden bij GitHub/Hostinger-deployments. Geen uploadmap of nieuwe cronjob nodig.
- Houd afbeeldingen mee in de databaseback-ups. Een databaseback-up is nu groter dan voorheen.
- Uploadgrens in PHP: `upload_max_filesize` minimaal `5M`, `post_max_size` minimaal `8M`. MySQL `max_allowed_packet` minimaal 8 MiB. Lagere hostlimieten kunnen een upload alsnog weigeren; de app meldt dit zonder geheime configuratie te tonen.
- Servercontrole op grootte, gedetecteerd rastertype en afmetingen (maximaal 8000 pixels per zijde en 24 megapixels). Geen SVG, GIF, video of uitvoerbare bestanden. De server voert uploadbytes nooit uit en levert afbeeldingen met een vast afbeeldingstype, `nosniff` en een beperkende Content Security Policy.
- Voor opslag geldt 100 MiB per bedrijf. Identieke uploads binnen hetzelfde bedrijf gebruiken dezelfde afbeelding. Losgeraakte afbeeldingen ouder dan 24 uur worden bij een volgende upload opgeruimd; afbeeldingen die nog aan een dienst gekoppeld zijn blijven behouden.
- Foto's worden in deze versie niet automatisch verkleind of van metadata ontdaan. Gebruik bij voorkeur een geoptimaliseerde webfoto.
- Aanmaken vereist een geldige sessie, CSRF-controle en toegang tot het bedrijf. De uploadlimiet is 30 geldige uploadpogingen per 10 minuten per bedrijf. De bestaande proefperioderegels gelden ook voor uploads.
- Bestaande externe HTTPS-afbeeldingen blijven ondersteund. De editor toont ze en laat ze staan totdat de beheerder ze vervangt of verwijdert. Het URL-invoerveld is vervangen door de uploadfunctie.

## Controle

De API-integratietest gebruikt echte multipart-uploadverzoeken naar een lokale PHP/MySQL-testomgeving en controleert authenticatie, CSRF, bedrijfsafscherming, foutieve bestandstypes, de limiet, opslag/download, deduplicatie en categoriebehoud. De UI-tests controleren voorbeeld, type/limiet, verwijderen en opnieuw opslaan zonder opnieuw te uploaden.
