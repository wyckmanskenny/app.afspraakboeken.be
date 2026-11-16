# Partneromgeving — 0.14.0

## Ingebruikname

1. Open als superadmin de nieuwe tab **Partners**.
2. Maak een partnerbedrijf aan. Accounts activeren staat standaard uit; klanten toevoegen staat aan.
3. Nodig één of meer medewerkers uit. Elke medewerker kiest zelf een wachtwoord via een persoonlijke, eenmalige link van 24 uur. Een e-mailadres kan niet tegelijk klantbeheerder en partnergebruiker zijn.
4. De partneromgeving werkt direct op `https://app.afspraakboeken.be/partners/`.
5. Voor het aparte adres: koppel `partners.afspraakboeken.be` aan een eigen webroot met HTTPS en plaats daar **alleen** het bestand `partner-host/index.html` als `index.html`. Geen databaseconfiguratie of wachtwoorden kopiëren. Dit startbestand toont de centraal bijgewerkte partneromgeving in een schermvullend frame; de adresbalk blijft op het partnersubdomein. Het frame communiceert op de app-origin, zodat geen brede CORS- of domeincookie-toegang nodig is.
6. Zodra dat adres werkt, zet in de bestaande privéconfiguratie op het hoogste niveau, naast `app_url`: `'partner_url' => 'https://partners.afspraakboeken.be',`. Nieuwe partneruitnodigingen verwijzen dan naar het aparte domein. Oude uitnodigingen op het app-adres blijven geldig tot hun vervaldatum. Zonder deze optionele instelling blijven uitnodigingen het werkende app/partners-adres gebruiken.

Het startbestand hoeft bij normale app-updates niet opnieuw te worden gekopieerd. De partnerpagina staat framing alleen toe vanaf zichzelf en `https://partners.afspraakboeken.be`. Het partnerdomein heeft geen toegang tot databasewachtwoorden.

## Rechten en gegevens

Partners gebruiken `ab_users.role=partner`, eigen HttpOnly host-only sessiecookies en uitsluitend `partner/*` API-routes. De gewone app-authenticatie accepteert geen partnerrol. Iedere aanvraag controleert actieve gebruiker, actief partnerbedrijf, sessie en bij mutaties CSRF. De twee portalen kunnen onafhankelijk aangemeld zijn.

Partnerleden zijn gekoppeld via `ab_partner_members`. Superadmin beheert rechten en medewerkers. Pauzeren trekt bestaande partnersessies in, zonder bedrijfsaccounts te pauzeren. Een partner kan geen gebruikersrechten, prijzen, CRM, agenda of facturatiegegevens van klanten aanpassen of lezen.

Klanten aanmaken levert een inactief account en een activatieverzoek op. De klant ontvangt zelf de wachtwoorduitnodiging; de partner krijgt die link niet terug. Mislukte mail laat het account behouden; de superadmin kan de bestaande klant opnieuw uitnodigen. Aanmaken is idempotent en beperkt tot 30 pogingen per partner per dag. Direct activeren vereist het aparte recht en een expliciete bevestiging dat de formule is afgesproken. Activeren beëindigt een eventuele proefbeperking, registreert geen betaling en laat factuurbedragen onder superadminbeheer.

Activatieverzoeken zijn zichtbaar in de superadmin-tab Partners. Er wordt momenteel geen aparte herinneringsmail voor partneractivatieverzoeken verzonden.

## Aanbrenglinks

Een persoonlijke link opent de bestaande registratiepagina met een ondoorzichtige partnercode. De aanvrager ziet wie de partner is en welke accountgegevens die kan volgen. Er is geen cross-site trackingcookie. De code wordt bij de gekozen registratie meegestuurd, aan de e-mailverificatietoken gekoppeld en pas na verificatie definitief in `ab_partner_accounts` vastgelegd. Een bedrijfsaccount kan maar één partner hebben; bestaande accounts worden niet automatisch overgenomen. Informatieaanvragen kunnen via `ab_partner_enquiries` worden gevolgd. Een informatieaanvraag wordt niet automatisch een bedrijfsaccount.

Niet-bevestigde partnerverwijzingen ouder dan 30 dagen worden bij volgende partnerregistraties opgeruimd. Inactieve partnerlinks accepteren geen nieuwe aanvragen. Een al verstuurde verificatie houdt de oorspronkelijke partnerkoppeling; het bedrijf mag zijn verificatie afronden, maar de gepauzeerde partner heeft geen toegang.

## Database en commissies

Additieve, herhaalbare migratie met advisory lock en auditmarkering `schema.partners_v1`:
- `ab_partners`: partnerbedrijf, code, rechten en revisie.
- `ab_partner_members`: persoonlijke logins per bedrijf.
- `ab_partner_accounts`: vaste klanttoewijzing, bron en activatiegegevens.
- `ab_partner_referrals`: tijdelijke koppeling aan e-mailverificatie.
- `ab_partner_enquiries`: aangebrachte informatieaanvragen.
- `ab_partner_commissions`: voorbereid grootboek met factuurreferentie, bedrag, valuta, vastgelegde afspraken, status en betaaldatum.

Commissies worden nog niet berekend of uitgekeerd; er is nog geen beheerformulier voor tarieven of betalingen. De dashboardtotalen zijn nul zolang er geen goedgekeurde boekingen in het commissiegrootboek staan. Een actief account is niet hetzelfde als een betaalde factuur. De toekomstige berekening moet aansluiten op bevestigde betalingen en de toen geldende partnerafspraken vastleggen.

## Controle

Lokale integratietests gebruiken een wegwerpdatabase en een SMTP-testserver. Ze controleren gescheiden rollen/sessies, CSRF, uitnodigingen, twee partners, activeren met/zonder recht, partner- en medewerkerpauze, bestaande klanttoegang, verificatietoewijzing en bescherming tegen overnemen van bestaande accounts. UI-tests controleren partnerlogin, klant toevoegen, superadminbeheer en de registratievermelding. Er worden geen productiepartners of klanten voor tests aangemaakt.
