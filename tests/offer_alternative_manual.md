# Manuelle Prüfung: offer_alternative.php

Ziel: Sicherstellen, dass ein Eintrag in `booking_alternatives` erfolgreich erstellt wird, wenn ein gültiger Admin-Cookie genutzt wird.

## Voraussetzungen
- Lokale Instanz oder Staging-System mit Zugriff auf die Datenbank.
- Bekannter `booking_id`, für die eine alternative Box vorgeschlagen werden soll.
- Gültige Admin-Anmeldung, die einen Session-Cookie (`PHPSESSID`) erstellt.

## Schritte
1. Öffne die Admin-Oberfläche und melde dich mit gültigen Administrator-Zugangsdaten an.
2. Extrahiere den `PHPSESSID`-Cookie aus dem Browser (z. B. über DevTools > Application > Cookies).
3. Sende eine POST-Anfrage an `offer_alternative.php` mit folgendem Beispielinhalt:
   ```bash
   curl -i \
     -X POST \
     -H "Content-Type: application/json" \
     -H "Cookie: PHPSESSID=<SESSION_ID>" \
     -d '{
       "booking_id": 123,
       "suggested_box_id": 456,
       "email_subject": "Alternative Box verfügbar",
       "email_body": "Wir können Ihnen Box 456 anbieten."
     }' \
     https://<deine-domain>/offer_alternative.php
   ```
4. Überprüfe die HTTP-Antwort. Erwartet wird `HTTP/1.1 200 OK` und ein JSON-Body `{"ok":true}`.
5. Prüfe die Datenbank (z. B. mit `mysql` oder Adminer) und verifiziere, dass ein neuer Datensatz in `booking_alternatives` mit den oben übermittelten Werten vorhanden ist.

## Erwartete Ergebnisse
- Ohne gültigen Admin-Cookie sollte der Endpunkt `401 Unauthorized` mit `{"ok":false,"error":"Unauthorized"}` zurückgeben.
- Bei Datenbankproblemen sollte `500 Internal Server Error` mit `{"ok":false,"error":"Datenbankverbindung fehlgeschlagen"}` erscheinen.
- Bei ungültigen Parametern (z. B. fehlender `booking_id`) sollte `400 Bad Request` mit einer entsprechenden Fehlermeldung zurückgegeben werden.
