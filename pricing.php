<?php
declare(strict_types=1);

// CORS – damit dein Widget auch von anderen Hosts abrufen kann
header('Access-Control-Allow-Origin: *');
header('Content-Type: application/json; charset=utf-8');

// ---- Preise & Rabatte zentral pflegen ----
// Wochentage-Keys: mo, di, mi, do, fr, sa, so
// Box-IDs müssen mit dem Widget übereinstimmen: '1','2','3'

$discountsByDay = [
  1  => 0.0,
  2  => 20.0,
  3  => 30.0,
  4  => 35.0,
  5  => 40.0,
  6  => 45.0,
  7  => 50.0,
  8  => 51.5,
  9  => 53.0,
  10 => 54.5,
  11 => 56.0,
  12 => 57.5,
  13 => 59.0,
  14 => 60.0,
  15 => 60.625,
  16 => 61.25,
  17 => 61.875,
  18 => 62.5,
  19 => 63.125,
  20 => 63.75,
  21 => 64.375,
  22 => 65.0,
  23 => 65.625,
  24 => 66.25,
  25 => 66.875,
  26 => 67.5,
  27 => 68.125,
  28 => 68.75,
  29 => 69.375,
  // ab 30 Tagen:
  30 => 70.0
];

// Für Abwärtskompatibilität zusätzlich eine staffelartige Liste erzeugen:
// größte passende Stufe gilt; für >30 bleibt 70% bestehen.
$discounts = [
  ['min_days' => 30, 'percent' => 70.0],
  ['min_days' => 29, 'percent' => 69.375],
  ['min_days' => 28, 'percent' => 68.75],
  ['min_days' => 27, 'percent' => 68.125],
  ['min_days' => 26, 'percent' => 67.5],
  ['min_days' => 25, 'percent' => 66.875],
  ['min_days' => 24, 'percent' => 66.25],
  ['min_days' => 23, 'percent' => 65.625],
  ['min_days' => 22, 'percent' => 65.0],
  ['min_days' => 21, 'percent' => 64.375],
  ['min_days' => 20, 'percent' => 63.75],
  ['min_days' => 19, 'percent' => 63.125],
  ['min_days' => 18, 'percent' => 62.5],
  ['min_days' => 17, 'percent' => 61.875],
  ['min_days' => 16, 'percent' => 61.25],
  ['min_days' => 15, 'percent' => 60.625],
  ['min_days' => 14, 'percent' => 60.0],
  ['min_days' => 13, 'percent' => 59.0],
  ['min_days' => 12, 'percent' => 57.5],
  ['min_days' => 11, 'percent' => 56.0],
  ['min_days' => 10, 'percent' => 54.5],
  ['min_days' => 9,  'percent' => 53.0],
  ['min_days' => 8,  'percent' => 51.5],
  ['min_days' => 7,  'percent' => 50.0],
  ['min_days' => 6,  'percent' => 45.0],
  ['min_days' => 5,  'percent' => 40.0],
  ['min_days' => 4,  'percent' => 35.0],
  ['min_days' => 3,  'percent' => 30.0],
  ['min_days' => 2,  'percent' => 20.0],
  ['min_days' => 1,  'percent' => 0.0],
];

$config = [
  'discountsByDay' => $discountsByDay,
  'discounts'      => $discounts, // Fallback
  'boxes' => [
    '1' => [
      'name'    => 'Marshall Bromley 750',
      'deposit' => 0, // „gratis“ in der Anzeige
      'prices'  => [
        'mo' => 39, 'di' => 39, 'mi' => 39, 'do' => 39,
        'fr' => 49, 'sa' => 49, 'so' => 49,
      ],
    ],
    '2' => [
      'name'    => 'Teufel Rockster Neo',
      'deposit' => 0,
      'prices'  => [
        'mo' => 34, 'di' => 34, 'mi' => 34, 'do' => 34,
        'fr' => 44, 'sa' => 44, 'so' => 44,
      ],
    ],
    '3' => [
      'name'    => 'Soundboks 4',
      'deposit' => 0,
      'prices'  => [
        'mo' => 34, 'di' => 34, 'mi' => 34, 'do' => 34,
        'fr' => 44, 'sa' => 44, 'so' => 44,
      ],
    ],
  ],
];

echo json_encode(['ok' => true, 'pricing' => $config], JSON_UNESCAPED_UNICODE);
