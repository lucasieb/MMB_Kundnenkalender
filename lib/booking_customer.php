<?php
declare(strict_types=1);

const BOOKING_PRIVACY_VERSION = '2026-10-20';

/** Validate the same structured customer fields for creation and admin edits. */
function booking_customer_fields(array $input, bool $partial = false): array {
 $fields = [];
 $definitions = [
  'customer_name'=>[140,'Vollständiger Name'],
  'customer_email'=>[200,'E-Mail-Adresse'],
  'customer_phone'=>[50,'Telefonnummer'],
  'billing_address_line1'=>[200,'Straße und Hausnummer'],
  'billing_postal_code'=>[20,'Postleitzahl'],
  'billing_city'=>[120,'Ort'],
 ];
 foreach ($definitions as $key=>[$max,$label]) {
  if ($partial && !array_key_exists($key,$input)) continue;
  if (!isset($input[$key]) || !is_scalar($input[$key])) throw new InvalidArgumentException("Bitte $label angeben.");
  $value = trim((string)$input[$key]);
  if ($value === '' || mb_strlen($value)>$max || preg_match('/[\x00-\x1F\x7F]/u',$value)) throw new InvalidArgumentException("Bitte $label gültig angeben.");
  $fields[$key]=$value;
 }
 if (isset($fields['customer_email']) && !filter_var($fields['customer_email'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Bitte eine gültige E-Mail-Adresse angeben.');
 if (isset($fields['customer_phone'])) {
  $phone=$fields['customer_phone']; $digits=preg_replace('/\D/','',$phone);
  if (!preg_match('/^\+?[0-9 ()\/.\-]+$/',$phone) || strlen($digits)<7 || strlen($digits)>15) throw new InvalidArgumentException('Bitte eine erreichbare Telefonnummer mit Vorwahl angeben.');
 }
 if (!$partial || array_key_exists('customer_birthdate',$input)) {
  $birth=trim((string)($input['customer_birthdate']??''));
  if ($birth!=='') {
   $date=DateTimeImmutable::createFromFormat('!Y-m-d',$birth);
   if (!$date || $date->format('Y-m-d')!==$birth || $birth<'1900-01-01' || $birth>date('Y-m-d')) throw new InvalidArgumentException('Bitte ein gültiges Geburtsdatum angeben oder das freiwillige Feld leer lassen.');
  }
  $fields['customer_birthdate']=$birth===''?null:$birth;
 }
 return $fields;
}
