# Kundenkommunikation – Stand 22.10.2026

## Live-Vorlagen

Eingang beim Kunden, interne neue Anfrage, Bestätigung, Änderung, Ablehnung, Storno und Alternativangebot verwenden `lib/booking_mail.php`. HTML und Klartext enthalten denselben gespeicherten Buchungsstand, einschließlich Komponenten, Setvorteil, Mietsumme, manueller Preisanpassung, Abwicklung, Anmerkung und passender Rechnungs-/Zieladresse. Alte Aufträge werden nicht neu kalkuliert. Eine Wunschzeit ist ausdrücklich noch abzustimmen.

Die vom Betreiber genannten Betriebsangaben stehen in `lib/communication_config.php`: Kreuzstraße 60 in Wesel, Rückgabe zur vereinbarten Abholzeit, aktuell keine Kaution, Vorauszahlung bei Versand/Lieferung und Rücksendung mit bereitgestellten Etiketten. Es gibt noch keinen aktiven Onlinezahlungsanbieter. Die Mails versprechen keinen fertigen Zahlungslink.

## Service-Erinnerungen

`booking_reminders.php` ist ausschließlich per CLI erreichbar. Der Workflow `Booking reminders` startet stündlich und sendet nur im lokalen Zeitfenster 09 Uhr Europe/Berlin. GitHub-Zeitpläne können verspätet laufen; die exakte Minute wird nicht garantiert. Fällt ein ganzer Tag aus, erfolgt kein verspäteter Sammelversand.

- 7 Tage vor Mietbeginn: Ausstattung, Termin, Abwicklung und offene Abstimmungen prüfen.
- 3 Tage vorher: gleiche aktuelle Übersicht plus Übergabe-/Rücksendeanweisungen.
- Nur bestätigte Buchungen mit verifizierter E-Mail-Adresse; TEST-Namen werden ausgeschlossen.
- Private Aktivierungsgrenze verhindert nachträgliche neue Automatik für historische Aufträge.
- Ein eindeutiger dauerhafter Versandauftrag je Buchung, Mietbeginn und Mailtyp verhindert doppelte Läufe. Empfängeradresse wird im Versandprotokoll nur gehasht gespeichert.
- Bei unklarem SMTP-Ergebnis wird `needs_review` protokolliert und nicht automatisch erneut gesendet. Der Workflow meldet dann einen Fehler.
- Keine Werbung, Bewertungsaufforderung oder automatische Neubestätigung durch die Erinnerung.

Aktivierung: `communication.private.php` außerhalb `public_html`, Dateirechte 0600, mit `enabled => true` und `activate_created_after` als UTC-Zeitstempel. Die Betriebsangaben können dort überschrieben werden. Keine Zugangsdaten ins Repository. Vor Aktivierung `php booking_reminders.php` als Trockenlauf ausführen. `--send` sendet; `--scheduled` beschränkt zusätzlich auf 09 Uhr.

## Noch nicht freigeschaltet

Onlinezahlungen, 2 % Vorauszahlungsskonto, automatisierte Rechnungen/Verträge, automatische Versandetiketten und Rezensionen. Händlerkonto, wirtschaftliche/regulatorische Abklärung der Zahlarten sowie die endgültigen Zahlungs-/Vertragsregeln sind Voraussetzungen für den nächsten Schritt. Die technischen und fachlichen Vorschläge werden separat dokumentiert.
