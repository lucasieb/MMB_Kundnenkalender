<?php
declare(strict_types=1);

final class ContractPDO extends PDO {
    public function __construct() {
        parent::__construct('sqlite:' . __DIR__ . '/test.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    }
    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false {
        if (preg_match('/^SHOW COLUMNS FROM\s+`?(\w+)`?/i', trim($query), $match)) {
            $query = "SELECT name AS Field FROM pragma_table_info('" . $match[1] . "')";
        }
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$fetchModeArgs);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        return parent::prepare(str_ireplace('NOW()', 'CURRENT_TIMESTAMP', $query), $options);
    }
}
function pdo(): PDO {
    static $connection;
    return $connection ??= new ContractPDO();
}
