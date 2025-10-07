<?php
declare(strict_types=1);

/**
 * Hilfsfunktionen rund um die Tabelle booking_alternatives.
 */

if (!function_exists('mmb_booking_alternatives_table_exists')) {
    /**
     * Prüft, ob die Tabelle booking_alternatives existiert (pro PDO-Instanz gecached).
     */
    function mmb_booking_alternatives_table_exists(PDO $pdo): bool
    {
        static $cache = [];
        $objectId = function_exists('spl_object_id') ? spl_object_id($pdo) : spl_object_hash($pdo);
        $key = $objectId . ':booking_alternatives';
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        try {
            $stmt = $pdo->query("SHOW TABLES LIKE 'booking_alternatives'");
            $exists = $stmt !== false && $stmt->fetchColumn() !== false;
        } catch (Throwable $e) {
            error_log('booking_alternatives existence check failed: ' . $e->getMessage());
            $exists = false;
        }

        $cache[$key] = $exists;
        return $exists;
    }
}

if (!function_exists('mmb_ensure_booking_alternatives_table')) {
    /**
     * Legt die Tabelle booking_alternatives bei Bedarf an.
     */
    function mmb_ensure_booking_alternatives_table(PDO $pdo): bool
    {
        if (mmb_booking_alternatives_table_exists($pdo)) {
            return true;
        }

        $sql = <<<'SQL'
CREATE TABLE IF NOT EXISTS `booking_alternatives` (
  `id` INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  `booking_id` INT UNSIGNED NOT NULL,
  `suggested_box_id` INT UNSIGNED NOT NULL,
  `email_subject` VARCHAR(255) DEFAULT NULL,
  `email_body` TEXT,
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  KEY `idx_booking` (`booking_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

        try {
            $pdo->exec($sql);
        } catch (Throwable $e) {
            error_log('booking_alternatives table creation failed: ' . $e->getMessage());
            return false;
        }

        return mmb_booking_alternatives_table_exists($pdo);
    }
}
