# Einheitlicher Buchungskatalog · Starttarife vom 21.10.2026

Die zentrale Konfiguration ist `lib/catalog.php`; die bestehenden Musikboxpreise und Mietdauerrabatte bleiben in `lib/pricing_config.php`. Alle neuen Preise sind vom Nutzer beauftragte Starttarife für die erste Ausbaustufe und können dort angepasst werden. Die serverseitige Berechnung in `lib/order_quote.php` ist maßgeblich. Es werden unverbindliche Anfragen angelegt, keine Onlinezahlungen oder garantierten Bestandsreservierungen.

| Produkt / Option | Starttarif und Mietdauer |
| --- | --- |
| SOUNDBOKS 4 / Teufel Rockster Neo | bisherige 34 € Mo–Do / 44 € Fr–So, Mietdauerrabatt unverändert |
| Marshall Bromley 750 | bisherige 39 € Mo–Do / 49 € Fr–So, Mietdauerrabatt unverändert |
| Zweite SOUNDBOKS | gleicher Wochentagstarif; bei anderen Modellen nicht verfügbar |
| DJ-Set | zwei SOUNDBOKS, zwei Stative enthalten; 10 % Setvorteil zusätzlich zum bisherigen Mietdauerrabatt |
| Party-Set | DJ-Set plus Fotobox; 20 € Vorteil auf das gewählte Fotoboxpaket |
| Event-Set | Party-Set plus 4×8-m-Zelt; weitere 20 € Vorteil auf das Zelt |
| Stativ / Mikrofon / Zusatzakku | je 9 € für bis zu 3 Kalendertage; danach 2 € je Stück/Tag |
| Rucksack | 5 € bis zu 3 Tage, danach 1 €/Tag; nur SOUNDBOKS |
| Pioneer DDJ-FLX4 | allein 29 €, mit Musikbox 19 € für bis zu 3 Tage; danach 5 €/Tag; eigener Laptop/Software erforderlich |
| Fotobox Digital / Print 200 / Print 700 | 139 / 219 / 295 € bis zu 5 Kalendertage; danach 40 €/Tag |
| Zelt 4×8 m | 99 € erster Kalendertag, danach 25 €/Tag; Freitag–Sonntag 149 € |
| Zelt-Seitenwände | 20 € pro Miete |
| Zelt Auf- und Abbau | 80 € Aufbau + 80 € Abbau, je zwei Personen und etwa eine Stunde kalkuliert; bei gewählter Montage im angezeigten Betrag enthalten |
| Abholung in Wesel | kostenlos; Selbstaufbau |
| Musikboxversand | 80 € je Musikbox für Hin- und Rückversand / Vorbereitung |
| Fotoboxversand | nicht angeboten, auch nicht in kombinierten Sets |
| Zelt-/Mischpultversand | Frachtkosten individuell auf Angebot; keine erfundene Paketpauschale |
| Lieferfahrt | individuell nach Entfernung auf Angebot; zusätzlich zur angezeigten Mietsumme |

SOUNDBOKS-Zusatzakkus sind auf drei begrenzt, die Anzahl Stative auf die Anzahl Musikboxen. Sets enthalten Stative ohne doppelte Berechnung. Neue Angaben wie Komponenten, Mengen, Set, Mietdauer, Tarifversion und offene Angebotskosten werden als kanonischer Snapshot in `bookings.order_json` gespeichert. Eigenständige Fotobox-/Zelt-/Mischpultanfragen haben `box_id=NULL`. Bestehende Anfragen behalten ihre bisherigen Preise und Daten. Preisüberschreibungen der Verwaltung werden als `manual_total` dokumentiert.

## Veröffentlichung

1. CI bestehen lassen und Main deployen.
2. `php migrate_order_fields.php` ausdrücklich per SSH ausführen (nur CLI, idempotent; alte Datensätze bleiben erhalten).
3. Kunden-Code aus `Kundenkalender` in den fünf bestehenden Builder-Einbettungen aktualisieren; `Adminbuchungsliste` ebenfalls aktualisieren und Builder veröffentlichen.
4. Asset-URLs werden beim Abruf revalidiert. Die Einbettungen benötigen bei reinen Änderungen an den externen JS-/CSS-Dateien keine erneute Kopie.

## Quellen und Preisgrundlage

- Vorhandene Fotoboxpreise/Leistungsmerkmale: https://www.mietmichbox.de/fotobox-mieten
- Hochwertiges 4×8-m-Zelt als Orientierung, anderer Serviceumfang: https://www.partyverleih-schreiner.de/MIETEQUIPMENT/Partyzelte-Zubehoer/Partyzelt-4x8m/
- Piktogramme lokal aus Lucide 0.468.0, ISC/Feather MIT: `LICENSE-LUCIDE.txt`; https://lucide.dev/license

Die neuen Zubehör-/Zelttarife und Personalansätze sind eigene anfängliche Kalkulationsannahmen, keine übernommenen Marktpreise. Montage berücksichtigt auch Abbau und reserviert vier Personenstunden zu je 40 €. Die tatsächlichen Logistikbedingungen und Fahrtkosten werden vor einer verbindlichen Bestätigung abgestimmt. Umgebende Website-Inhalte und das bisherige separate Fotoboxformular bleiben für den späteren Website-Umbau erhalten.
