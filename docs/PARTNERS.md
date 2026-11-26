# Partneromgeving — 0.22.3

## Ingebruikname

1. Open als superadmin de nieuwe tab **Partners**.
2. Vul bedrijfsnaam, contactpersoon, e-mail, telefoon en het afgesproken commissiepercentage in. Kies **Partner aanmaken en uitnodigen**: de contactpersoon krijgt meteen een persoonlijke welkomstmail. Accounts activeren staat standaard uit; klanten toevoegen staat aan.
3. De contactpersoon kiest zelf een wachtwoord via een eenmalige link van 24 uur. Extra gebruikers en nieuwe uitnodigingen beheer je bij de partner. Hetzelfde e-mailadres kan zowel een klantaccount (of superadminaccount) als een partneraccount hebben. Voor het partnerportaal stelt de ontvanger een eigen wachtwoord in; het bestaande app-wachtwoord en de klanttoegang blijven behouden. Bij een onzekere of mislukte mail blijft de aangemaakte partner bewaard; de beheerder ziet de status en kan opnieuw uitnodigen. Het contactadres wijzigen verandert bestaande loginadressen niet.
4. De partneromgeving werkt direct op `https://app.afspraakboeken.be/partners/`.
5. Voor het aparte adres: koppel `partners.afspraakboeken.be` aan een eigen webroot met HTTPS en plaats daar **alleen** het bestand `partner-host/index.html` als `index.html`. Geen databaseconfiguratie of wachtwoorden kopiëren. Dit startbestand toont de centraal bijgewerkte partneromgeving in een schermvullend frame; de adresbalk blijft op het partnersubdomein. Het frame communiceert op de app-origin, zodat geen brede CORS- of domeincookie-toegang nodig is.
6. In productie verwijzen nieuwe uitnodigingen standaard naar `https://partners.afspraakboeken.be/`. Lokale testomgevingen gebruiken hun eigen app-adres met `/partners/`. De bestaande optionele `partner_url`-instelling blijft ondersteund. Oude uitnodigingen op het app-adres blijven geldig tot hun vervaldatum.

Het startbestand hoeft bij normale app-updates niet opnieuw te worden gekopieerd. De partnerpagina staat framing alleen toe vanaf zichzelf en `https://partners.afspraakboeken.be`. Het partnerdomein heeft geen toegang tot databasewachtwoorden.

## Rechten en gegevens

Partners gebruiken `ab_users.role=partner`, eigen HttpOnly host-only sessiecookies en uitsluitend `partner/*` API-routes. De gewone app-authenticatie accepteert geen partnerrol. Iedere aanvraag controleert actieve gebruiker, actief partnerbedrijf, sessie en bij mutaties CSRF. De twee portalen kunnen onafhankelijk aangemeld zijn.

Partnerleden zijn gekoppeld via `ab_partner_members`. Superadmin beheert rechten en medewerkers. Pauzeren trekt bestaande partnersessies in, zonder bedrijfsaccounts te pauzeren. Een partner kan geen gebruikersrechten, prijzen, CRM, agenda of facturatiegegevens van klanten aanpassen of lezen.

Klanten aanmaken levert een inactief account en een activatieverzoek op. De klant ontvangt zelf de wachtwoorduitnodiging; de partner krijgt die link niet terug. Mislukte mail laat het account behouden; de superadmin kan de bestaande klant opnieuw uitnodigen. Aanmaken is idempotent en beperkt tot 30 pogingen per partner per dag. Direct activeren vereist het aparte recht en een expliciete bevestiging dat de formule is afgesproken. Activeren beëindigt een eventuele proefbeperking, registreert geen betaling en laat factuurbedragen onder superadminbeheer.

Activatieverzoeken zijn zichtbaar in de superadmin-tab Partners. Er wordt momenteel geen aparte herinneringsmail voor partneractivatieverzoeken verzonden.

## Aanbrenglinks

Een persoonlijke link opent de bestaande registratiepagina met een ondoorzichtige partnercode. De aanvrager ziet wie de partner is en welke accountgegevens die kan volgen. Er is geen cross-site trackingcookie. De code wordt bij de gekozen registratie meegestuurd, aan de e-mailverificatietoken gekoppeld en pas na verificatie definitief in `ab_partner_accounts` vastgelegd. Een bedrijfsaccount kan maar één partner hebben; bestaande accounts worden niet automatisch overgenomen. Informatieaanvragen kunnen via `ab_partner_enquiries` worden gevolgd. Een informatieaanvraag wordt niet automatisch een bedrijfsaccount.

Niet-bevestigde partnerverwijzingen ouder dan 30 dagen worden bij volgende partnerregistraties opgeruimd. Inactieve partnerlinks accepteren geen nieuwe aanvragen. Een al verstuurde verificatie houdt de oorspronkelijke partnerkoppeling; het bedrijf mag zijn verificatie afronden, maar de gepauzeerde partner heeft geen toegang.

## Portaal en commissies

De partner ziet **Overzicht**, **Mijn klanten**, **Mijn links**, **Commissies** en **Mijn account**. Het overzicht toont lopende trials, actieve klanten, commissie in de huidige Belgische kalendermaand en trials die binnen zeven dagen eindigen. Klanten kunnen worden gezocht en gefilterd. Een inactief of verlopen proefaccount telt niet als actieve klant.

De persoonlijke partnerlink start een proefperiode van 14 dagen na e-mailverificatie. Via **Mijn links** kan een extra link met een campagnelabel worden gemaakt. De partnercode blijft gelijk; er worden geen extra accounts of wachtwoorden aangemaakt bij het genereren. Campagnelabels zijn bedoeld voor links, niet als gegarandeerde marketingrapportage. Zet geen persoonsgegevens in campagnenamen.

Afspraakboeken factureert de klant. Er is geen vaste partner-verkoopprijs of minimum van €15 ingesteld. De beheerder legt de afgesproken formule en prijs per bedrijf vast bij **Klanten beheren → Administratie**. Een trial of accountactivatie is geen betaling en levert op zichzelf geen commissie op.

Bij **Partners → Beheren → Ontvangen abonnement registreren** kiest de beheerder een gekoppelde klant, unieke betalingsreferentie, ontvangstdatum en werkelijk ontvangen abonnementsgeld exclusief btw. Na bevestiging wordt de commissie berekend met het dan geldende partnerpercentage. Berekening en opslag gebeuren in centen/basispunten, afgerond op een hele eurocent; grondslag en percentage worden per boeking vastgelegd. Een gewijzigd partnerpercentage herberekent oude boekingen niet.

Een ontvangen jaarbetaling telt volledig mee in de maand van ontvangst. De maandtotalen zijn dus geen maandelijkse spreiding van een jaarfactuur. Het overzicht onderscheidt verdiende, openstaande en uitbetaalde commissie. De partner kan de geselecteerde maand als CSV exporteren. Facturatie, incasso en bankoverschrijvingen blijven buiten deze app: er is nog geen koppeling die ontvangen betalingen automatisch importeert.

**Uitbetaling registreren** is uitsluitend bedoeld voor een werkelijk uitgevoerde betaling aan de partner. Een foutieve, nog niet uitbetaalde commissie kan vervallen; de originele regel blijft bewaard. Uitbetaalde commissies worden niet via deze actie teruggedraaid. Terugbetalingen en correcties na uitbetaling vereisen afstemming in de eigen administratie; er is nog geen automatische negatieve correctieboeking.

Dubbele verzoeken en unieke betalingsreferenties mogen geen dubbele commissie opleveren. Alleen superadmin kan bedragen, percentages en uitbetalingen vastleggen; partners kunnen uitsluitend hun eigen overzicht lezen. Nieuwe partners krijgen expliciet een percentage; bestaande partners starten bij de migratie op 0% tot de beheerder een afspraak invoert.

## Database

Additieve, herhaalbare migratie met advisory lock en auditmarkering `schema.partners_v1`:
- `ab_partners`: partnerbedrijf, code, rechten en revisie.
- `ab_partner_members`: persoonlijke logins per bedrijf.
- `ab_partner_accounts`: vaste klanttoewijzing, bron en activatiegegevens.
- `ab_partner_referrals`: tijdelijke koppeling aan e-mailverificatie.
- `ab_partner_enquiries`: aangebrachte informatieaanvragen.
- `ab_partner_commissions`: voorbereid grootboek met unieke betalingsreferentie, bedrag, valuta, vastgelegde afspraken, status en betaaldatum.

De aanvullende v2-migratie voegt contactgegevens, commissiepercentage, eenmalige aanmaakverzoeken en betalingsgegevens voor commissies toe. De migratie is additief en herhaalbaar; bestaande klanten, toegang en historische commissiegegevens blijven bewaard. Commissieboekingen leggen ontvangstdatum, unieke betalingsreferentie, nettobasis, percentage, ontvangstmaand en status vast. Er vindt geen automatische uitbetaling plaats.

## Controle

Lokale integratietests gebruiken een wegwerpdatabase en een SMTP-testserver. Ze controleren gescheiden rollen/sessies, CSRF, uitnodigingen, twee partners, activeren met/zonder recht, partner- en medewerkerpauze, bestaande klanttoegang, verificatietoewijzing en bescherming tegen overnemen van bestaande accounts. UI-tests controleren partnerlogin, klant toevoegen, superadminbeheer en de registratievermelding. Er worden geen productiepartners of klanten voor tests aangemaakt.

## Uitnodigingen en activatie (0.22.1)

Partneruitnodigingen gebruiken **hallo@afspraakboeken.be** als afzender via de bestaande SMTP-koppeling. De mail bevat de eenmalige wachtwoordknop en een aparte link naar het partnerportaal. De mailprovider moet dit afzenderadres toestaan; gebruik hiervoor de mailbox of een door de provider geautoriseerde afzender. De app maakt geen mailbox aan en wijzigt geen SMTP-wachtwoorden.

In de partnerlijst staat **Geactiveerd**, **Wacht op activatie** of **Nog niet uitgenodigd**. Dit staat los van toegangsrechten (toegestaan/gepauzeerd). Een partner is geactiveerd zodra minstens één partnergebruiker via de persoonlijke uitnodiging een wachtwoord heeft ingesteld. Voor bestaande accounts wordt een ingesteld wachtwoord herkend; een historisch tijdstip wordt uitsluitend uit een bestaande activatie-audit afgeleid.

Bij elke partnergebruiker staan de activatie, laatste uitnodiging en verzendstatus. **Verstuurd** betekent dat de SMTP-server het bericht heeft aangenomen, niet dat inboxbezorging bewezen is. Bij een fout verschijnt uitsluitend een veilige categorie, bijvoorbeeld ontbrekende mailinstellingen, SMTP-aanmelding of geweigerde afzender. Wachtwoorden, servertranscripten en tokens worden nooit in foutmeldingen opgeslagen. Een nieuwe uitnodiging vervangt de vorige ongebruikte link; een bestaand wachtwoord of eerdere activatie blijft geldig totdat de ontvanger zelf een nieuw wachtwoord instelt.

Bestaande partners zonder gebruiker krijgen niet automatisch een mail bij deze update. Open **Partnergebruikers** en nodig de contactpersoon uit. Nieuwe partners krijgen hun uitnodiging meteen bij aanmaken. Het versturen heeft geen automatische retries: herhaal bewust via **Uitnodiging opnieuw sturen** als dat nodig is.

## Partner verwijderen

**Partner verwijderen** vraagt een aparte bevestiging. Het partnerbedrijf krijgt een verwijderdatum, toegang en partnerlinks stoppen, gebruikers worden uitgeschakeld, bestaande sessies worden ingetrokken en ongebruikte uitnodigingen vervallen. Nog niet bevestigde verwijzingen worden losgemaakt; de geïnteresseerde klant kan zijn proefaccount wel afronden. Bestaande klanten, hun gegevens en commissieboekingen blijven bewaard.

Verwijderde partners verdwijnen uit de standaardlijst. Met **Toon ook verwijderde partners** kan de superadmin hun historie terugvinden. Gegevens aanpassen, uitnodigen, klanten activeren en nieuwe ontvangsten boeken zijn daar niet beschikbaar; openstaande historische commissies kunnen nog als uitbetaald of vervallen geregistreerd worden. Verwijderen is geen persoonsgegevens-wisactie en maakt het oude partnerloginadres niet opnieuw beschikbaar voor een andere partner. Een klantaccount met hetzelfde e-mailadres blijft bruikbaar.

De herhaalbare v3-migratie voegt verwijdergegevens en uitnodigings-/activatievelden toe. Een interne poging-ID voorkomt dat een laat SMTP-resultaat de status van een nieuwere uitnodiging overschrijft.


## Klant en partner met hetzelfde e-mailadres (0.22.3)

De app en het partnerportaal hebben elk een eigen aanmeldruimte. Eén e-mailadres kan één app-account (klantbeheerder of superadmin) en één partneraccount hebben. De accounts hebben verschillende gebruikers-ID's, wachtwoorden, uitnodigingen en sessies. Een klant als partner uitnodigen geeft geen extra toegang tot klantgegevens en koppelt bestaande klanten niet automatisch aan commissie. Ook een partner kan met hetzelfde e-mailadres een klantaccount of proefaccount aanmaken.

De herhaalbare v4-migratie voegt de gegenereerde kolom `ab_users.login_scope` toe: `partner` voor partnergebruikers, anders `app`. Eerst wordt de unieke index `ab_user_email_scope(email,login_scope)` toegevoegd en gecontroleerd. Daarna vervalt de oude unieke index op alleen e-mail. De bestaande databasecollatie blijft van toepassing, zodat hoofdlettervarianten binnen hetzelfde portaal geen tweede login opleveren. Bestaande gebruikers-ID's, wachtwoorden, uitnodigingen en sessies worden niet gewijzigd. Gelijktijdige migraties worden met de bestaande advisory lock afgeschermd.

Controleer na uitrol `/api/?route=health` voordat je dubbele e-mailadressen aanmaakt. Na het aanmaken van accounts in beide portalen kan de oude globale e-mailconstraint niet zonder gegevensafhandeling worden teruggezet; draai deze migratie daarom niet blind terug. Een herstel moet de twee identiteiten en hun verwijzingen behouden.
