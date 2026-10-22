<?php
declare(strict_types=1);
/** Operational facts explicitly provided by the operator on 22 October 2026. */
function mmb_communication_config(): array {
 $defaults=[
  'enabled'=>false,
  'pickup_address'=>'Kreuzstraße 60, 46483 Wesel',
  'pickup_return'=>'Am Ende der Mietlaufzeit zur gleichen vereinbarten Uhrzeit wie bei der Abholung, sofern wir nichts anderes vereinbart haben. Beispiel: Freitag 12:00 Uhr bis Sonntag 12:00 Uhr = 2 Miettage.',
  'payment'=>'Bei Versand und Lieferung ist Vorauszahlung erforderlich, sofern nicht ausdrücklich anders vereinbart. Den Zahlungsweg stimmen wir persönlich mit dir ab. Bei Abholung ist auch Barzahlung vor Ort möglich.',
  'deposit'=>'Aktuell erheben wir keine Kaution.',
  'shipping_return'=>'Bewahre alle Verpackungen und das Verpackungsmaterial auf. Packe die gesamte Ausstattung unmittelbar nach Ende deiner Miete wieder sicher ein und sende sie mit unseren bereitgestellten Rücksendeetiketten unverzüglich an uns zurück. Fehlt ein Label, melde dich bitte vor dem Versand.',
  'delivery_return'=>'Rücknahme und Uhrzeit stimmen wir persönlich mit dir ab.',
 ];
 $path=dirname(__DIR__,2).'/communication.private.php';$private=is_file($path)?require $path:[];
 return array_replace($defaults,is_array($private)?$private:[]);
}
