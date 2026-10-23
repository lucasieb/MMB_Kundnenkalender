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
 if (!$partial || array_key_exists('customer_type',$input) || array_key_exists('company_name',$input)) {
  $type=$input['customer_type']??'private';
  if(!is_string($type)||!in_array($type,['private','business'],true))throw new InvalidArgumentException('Bitte Privat oder Gewerblich wählen.');
  $company=$input['company_name']??'';
  if(!is_scalar($company))throw new InvalidArgumentException('Bitte einen gültigen Unternehmensnamen angeben.');
  $company=trim((string)$company);
  if($type==='business'&&($company===''||mb_strlen($company)>200||preg_match('/[\x00-\x1F\x7F]/u',$company)))throw new InvalidArgumentException('Bitte den vollständigen Unternehmensnamen angeben.');
  $fields['customer_type']=$type;$fields['company_name']=$type==='business'?$company:null;
 }
 return $fields;
}

/** Additional company information follows the same bounds as billing data. */
function booking_delivery_company(array $details): void {
 if(isset($details['customer_type'])&&!in_array($details['customer_type'],['private','business'],true))throw new InvalidArgumentException('Ungültiger Kundentyp der Lieferadresse.');
 $value=$details['company_name']??'';
 if(!is_scalar($value)||mb_strlen((string)$value)>200||preg_match('/[\x00-\x1F\x7F]/u',(string)$value))throw new InvalidArgumentException('Bitte einen gültigen Unternehmensnamen der Lieferadresse angeben.');
 if(($details['customer_type']??'private')==='business'&&trim((string)$value)==='')throw new InvalidArgumentException('Bitte den Unternehmensnamen der Lieferadresse angeben.');
}


/** Resolve the explicit same-as-billing choice; legacy addresses are never inferred on reads. */
function booking_destination_details(array $booking, array $details): array {
 if (($details['same_as_billing'] ?? false) !== true) return $details;
 foreach (['address_line1'=>'billing_address_line1','postal_code'=>'billing_postal_code','city'=>'billing_city','company_name'=>'company_name'] as $target=>$source) {
  $details[$target]=trim((string)($booking[$source]??''));
 }
 $details['customer_type']=$booking['customer_type']??'private';
 unset($details['address_extra']);
 foreach(['contact_name'=>'customer_name','contact_email'=>'customer_email','contact_phone'=>'customer_phone'] as $target=>$source)if(empty($details[$target])&&!empty($booking[$source]))$details[$target]=$booking[$source];
 return $details;
}
