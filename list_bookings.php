<?php
declare(strict_types=1);

require __DIR__ . '/cors.php';        // <-- NEU: muss vor jeglicher Ausgabe stehen
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';        // falls genutzt

header('Content-Type: application/json; charset=utf-8');

require_admin();

const MMB_FULFILLMENT_DEFS = [
    'pickup' => [
        'label' => 'Abholung',
        'full' => 'Abholung in Wesel',
        'display_suffix' => 'in Wesel',
        'price_delta' => 0.0,
        'note' => '',
        'info_lines' => [
            '📍 Treffpunkt stimmen wir individuell in Wesel ab.',
            '🕚 Abholung ab deinem Miettag um 11:00 Uhr möglich.',
            '🔁 Rückgabe am Folgetag nach Mietende bis 10:00 Uhr.',
        ],
    ],
    'shipping' => [
        'label' => 'Versand',
        'full' => 'Versand (deutschlandweit)',
        'display_suffix' => 'ganz.de',
        'price_delta' => 80.0,
        'note' => 'inkl. Express-Hin- & Rückversand sowie Vorbereitungspauschale',
        'info_lines' => [
            '🚚 Wir versenden deine Musikbox einen Tag vor Mietbeginn per Express.',
            '📦 Rückversand am letzten Miettag mit dem beiliegenden QR-Code.',
            '📝 Bitte halte Lieferadresse sowie Kontaktinfos bereit, falls noch nicht übermittelt.',
        ],
    ],
    'delivery' => [
        'label' => 'Lieferung',
        'full' => 'Lieferung (NRW-weit)',
        'display_suffix' => 'ganz.nrb',
        'price_delta' => 0.0,
        'note' => 'zzgl. individueller Lieferpauschale – wir melden uns mit einem Angebot',
        'info_lines' => [
            '🚗 Wir liefern die Musikbox persönlich innerhalb von NRW.',
            '🕒 Wunschzeit am Mittag (halbstündlich) stimmen wir individuell ab.',
            '📍 Bitte teile uns Treffpunkt oder Lieferadresse mit.',
        ],
    ],
];

function mmb_trim(mixed $value): string
{
    return trim((string) ($value ?? ''));
}

function mmb_boolish(mixed $value): ?bool
{
    if (is_bool($value)) {
        return $value;
    }

    if (is_numeric($value)) {
        return ((int) $value) === 1;
    }

    if (is_string($value)) {
        $normalized = strtolower(trim($value));
        if ($normalized === '') {
            return null;
        }
        if (in_array($normalized, ['1', 'true', 'yes', 'y', 'on', 'ja'], true)) {
            return true;
        }
        if (in_array($normalized, ['0', 'false', 'no', 'n', 'off', 'nein'], true)) {
            return false;
        }
    }

    return null;
}

/**
 * @param mixed $raw
 * @return array<string,mixed>
 */
function mmb_decode_fulfillment_details(mixed $raw): array
{
    if (is_array($raw)) {
        return $raw;
    }

    if (is_string($raw)) {
        $trimmed = trim($raw);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }
    }

    return [];
}

function mmb_fulfillment_display_label(string $label, string $suffix): string
{
    $label = trim($label);
    $suffix = trim($suffix);

    return $suffix !== '' ? ($label . ' (' . $suffix . ')') : $label;
}

/**
 * @param array<string,mixed> $details
 */
function mmb_normalize_fulfillment_details(array $details, string $method): array
{
    $contact = [
        'name' => mmb_trim($details['contact_name'] ?? ($details['name'] ?? '')),
        'email' => mmb_trim($details['contact_email'] ?? ($details['email'] ?? '')),
        'phone' => mmb_trim($details['contact_phone'] ?? ($details['phone'] ?? '')),
        'consent' => mmb_boolish($details['consent_contact'] ?? ($details['consent_whatsapp'] ?? null)),
    ];

    $shipping = [
        'address_line1' => mmb_trim($details['address_line1'] ?? ''),
        'address_line2' => mmb_trim($details['address_line2'] ?? ''),
        'postal_code' => mmb_trim($details['postal_code'] ?? ''),
        'city' => mmb_trim($details['city'] ?? ''),
        'address_extra' => mmb_trim($details['address_extra'] ?? ''),
    ];

    $delivery = [
        'meeting_point' => mmb_trim($details['meeting_point'] ?? ''),
        'preferred_time' => mmb_trim($details['preferred_time'] ?? ''),
    ];

    $meta = [
        'submitted_at' => mmb_trim($details['submitted_at'] ?? ''),
        'submitted_via' => mmb_trim($details['submitted_via'] ?? ''),
        'booking_display_id' => mmb_trim($details['booking_display_id'] ?? ''),
    ];

    $requiresDetails = in_array($method, ['shipping', 'delivery'], true);
    $missing = [];

    if ($requiresDetails) {
        if ($contact['name'] === '') {
            $missing[] = 'Kontakt (Name)';
        }
        if ($contact['phone'] === '') {
            $missing[] = 'Kontakt (Telefon)';
        }
        if ($contact['email'] === '') {
            $missing[] = 'Kontakt (E-Mail)';
        }

        if ($method === 'shipping') {
            if ($shipping['address_line1'] === '') {
                $missing[] = 'Lieferadresse (Straße)';
            }
            if ($shipping['postal_code'] === '' || $shipping['city'] === '') {
                $missing[] = 'Lieferadresse (PLZ/Ort)';
            }
        } elseif ($method === 'delivery') {
            if ($delivery['meeting_point'] === '') {
                $missing[] = 'Treffpunkt';
            }
            if ($delivery['preferred_time'] === '') {
                $missing[] = 'Wunschzeit';
            }
        }
    }

    return [
        'raw' => $details,
        'contact' => $contact,
        'shipping' => $shipping,
        'delivery' => $delivery,
        'meta' => $meta,
        'missing' => $missing,
        'requires_details' => $requiresDetails,
        'needs_attention' => $requiresDetails && $missing !== [],
    ];
}

/**
 * @param array<string,mixed> $row
 * @param array<string,mixed> $details
 */
function mmb_infer_fulfillment_method(array $row, array $details): string
{
    $method = strtolower(mmb_trim($row['fulfillment_method'] ?? ''));
    if (in_array($method, ['pickup', 'shipping', 'delivery'], true)) {
        return $method;
    }

    $detailMethod = strtolower(mmb_trim($details['method'] ?? ''));
    if (in_array($detailMethod, ['pickup', 'shipping', 'delivery'], true)) {
        return $detailMethod;
    }

    foreach (['fulfillment_details_json', 'fulfillment_details'] as $key) {
        if (!array_key_exists($key, $row)) {
            continue;
        }
        $raw = $row[$key];
        if (is_array($raw)) {
            $candidate = strtolower(mmb_trim($raw['method'] ?? ''));
            if (in_array($candidate, ['pickup', 'shipping', 'delivery'], true)) {
                return $candidate;
            }
        } elseif (is_string($raw)) {
            $candidateDecoded = json_decode($raw, true);
            if (is_array($candidateDecoded)) {
                $candidate = strtolower(mmb_trim($candidateDecoded['method'] ?? ''));
                if (in_array($candidate, ['pickup', 'shipping', 'delivery'], true)) {
                    return $candidate;
                }
            }
        }
    }

    foreach (['fulfillment_label', 'fulfillment_note'] as $textKey) {
        if (!array_key_exists($textKey, $row)) {
            continue;
        }
        $text = strtolower((string) $row[$textKey]);
        if ($text === '') {
            continue;
        }
        if (strpos($text, 'versand') !== false) {
            return 'shipping';
        }
        if (strpos($text, 'liefer') !== false) {
            return 'delivery';
        }
    }

    return 'pickup';
}

/**
 * @param array<string,mixed> $lines
 * @return list<string>
 */
function mmb_filter_info_lines(array $lines): array
{
    $out = [];
    foreach ($lines as $line) {
        $line = mmb_trim($line);
        if ($line !== '') {
            $out[] = $line;
        }
    }

    return $out;
}

/**
 * @param array<string,mixed> $row
 */
function mmb_enrich_booking_row(array $row): array
{
    $rawDetails = $row['fulfillment_details_json'] ?? ($row['fulfillment_details'] ?? null);
    $details = mmb_decode_fulfillment_details($rawDetails);
    $method = mmb_infer_fulfillment_method($row, $details);

    $def = MMB_FULFILLMENT_DEFS[$method] ?? MMB_FULFILLMENT_DEFS['pickup'];

    $label = mmb_trim($row['fulfillment_label'] ?? '') ?: (string) ($def['label'] ?? '');
    $note = mmb_trim($row['fulfillment_note'] ?? '');
    if ($note === '' && isset($def['note'])) {
        $note = (string) $def['note'];
    }

    $price = isset($row['fulfillment_price_delta'])
        ? (float) $row['fulfillment_price_delta']
        : (float) ($def['price_delta'] ?? 0.0);

    $displaySuffix = (string) ($def['display_suffix'] ?? '');
    $displayLabel = mmb_fulfillment_display_label($label, $displaySuffix);

    $structured = mmb_normalize_fulfillment_details($details, $method);
    $requiresDetails = (bool) ($structured['requires_details'] ?? false);
    $missing = $structured['missing'] ?? [];
    $needsAttention = (bool) ($structured['needs_attention'] ?? false);

    $row['fulfillment_method'] = $method;
    $row['fulfillment_label'] = $label;
    $row['fulfillment_note'] = $note;
    $row['fulfillment_price_delta'] = $price;
    $row['fulfillment_display_label'] = $displayLabel;
    $row['fulfillment_requires_details'] = $requiresDetails;
    $row['fulfillment_missing_details'] = $missing;
    $row['fulfillment_needs_attention'] = $needsAttention;
    $row['fulfillment_details_structured'] = $structured;
    $row['fulfillment_details_raw'] = $structured['raw'];
    $row['fulfillment'] = [
        'method' => $method,
        'label' => $label,
        'full' => (string) ($def['full'] ?? $label),
        'note' => $note,
        'price_delta' => $price,
        'display_suffix' => $displaySuffix,
        'display_label' => $displayLabel,
        'info_lines' => mmb_filter_info_lines((array) ($def['info_lines'] ?? [])),
        'requires_details' => $requiresDetails,
        'missing_details' => $missing,
        'needs_attention' => $needsAttention,
        'details' => $structured,
    ];

    if (!isset($row['display_id']) || mmb_trim($row['display_id']) === '') {
        $row['display_id'] = sprintf('00%03d', (int) ($row['id'] ?? 0) + 100);
    }

    return $row;
}

/**
 * Gibt eine JSON-Antwort zurück und stellt sicher, dass immer gültiges JSON erzeugt wird.
 */
function mmb_json_response(array $payload, int $status = 200): void {
    http_response_code($status);
    $json = json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );

    if ($json === false) {
        $fallback = json_encode(
            ['ok' => false, 'error' => 'json_encode_failed', 'details' => json_last_error_msg()],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        echo $fallback !== false ? $fallback : '{"ok":false,"error":"json_encode_failed"}';
        return;
    }

    echo $json;
}

function mmb_exception_payload(Throwable $e): array {
    return [
        'type' => get_class($e),
        'message' => $e->getMessage(),
        'code' => $e->getCode(),
        'file' => basename($e->getFile()),
        'line' => $e->getLine(),
    ];
}

$box_id = isset($_GET['box_id']) ? (int) $_GET['box_id'] : null;
$status = isset($_GET['status']) ? trim((string) $_GET['status']) : null;
$from   = isset($_GET['from'])   ? trim((string) $_GET['from'])   : null;
$to     = isset($_GET['to'])     ? trim((string) $_GET['to'])     : null;

try {
    $pdo = pdo();

    $sql = "
        SELECT
            b.*,
            bx.name AS box_name,
            CONCAT('00', LPAD(b.id + 100, 3, '0')) AS display_id
        FROM bookings b
        LEFT JOIN boxes bx ON bx.id = b.box_id
        WHERE 1 = 1
    ";

    $params = [];

    if ($box_id) {
        $sql .= " AND b.box_id = ?";
        $params[] = $box_id;
    }

    if ($status !== null && $status !== '') {
        $sql .= " AND b.status = ?";
        $params[] = $status;
    }

    if ($from) {
        $sql .= " AND b.end_date >= ?";
        $params[] = $from;
    }

    if ($to) {
        $sql .= " AND b.start_date <= ?";
        $params[] = $to;
    }

    $sql .= " ORDER BY b.created_at DESC, b.id DESC LIMIT 500";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $items = array_map('mmb_enrich_booking_row', $rows);

    $methodCounts = [
        'pickup' => 0,
        'shipping' => 0,
        'delivery' => 0,
    ];
    $needsAttentionCount = 0;
    $requiresDetailsCount = 0;

    foreach ($items as $item) {
        $method = strtolower((string) ($item['fulfillment_method'] ?? ''));
        if ($method === '') {
            $method = 'pickup';
        }
        if (!array_key_exists($method, $methodCounts)) {
            $methodCounts[$method] = 0;
        }
        $methodCounts[$method]++;

        if (!empty($item['fulfillment_needs_attention'])) {
            $needsAttentionCount++;
        }
        if (!empty($item['fulfillment_requires_details'])) {
            $requiresDetailsCount++;
        }
    }

    mmb_json_response([
        'ok'    => true,
        'items' => $items,
        'meta'  => [
            'count' => count($items),
            'needs_attention' => $needsAttentionCount,
            'requires_details' => $requiresDetailsCount,
            'by_method' => $methodCounts,
        ],
    ]);
} catch (Throwable $e) {
    error_log(sprintf('[list_bookings] %s in %s:%d', $e->getMessage(), $e->getFile(), $e->getLine()));
    mmb_json_response([
        'ok'        => false,
        'error'     => 'Daten konnten nicht geladen werden',
        'details'   => $e->getMessage(),
        'exception' => mmb_exception_payload($e),
        'trace'     => array_slice(explode("\n", $e->getTraceAsString()), 0, 10),
    ], 500);
}
