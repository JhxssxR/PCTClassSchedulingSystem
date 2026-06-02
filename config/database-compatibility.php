<?php
/**
 * SQL Compatibility Helper Functions
 * Bridges differences between MySQL and PostgreSQL
 */

/**
 * Replace MySQL-specific SQL with PostgreSQL-compatible SQL
 */
function convert_sql_for_postgresql($sql) {
    // Don't modify if already converted or if it's simple
    if (empty($sql)) {
        return $sql;
    }

    // Replace GROUP_CONCAT with string_agg for PostgreSQL
    // Pattern: GROUP_CONCAT(col ORDER BY ... SEPARATOR ',')
    $sql = preg_replace_callback(
        '/GROUP_CONCAT\s*\(\s*([^,]+)\s+ORDER\s+BY\s+([^S]+)\s+SEPARATOR\s+[\'"]([^\'"]+)[\'"]\s*\)/i',
        function($matches) {
            return "string_agg($matches[1]::text, '$matches[3]' ORDER BY $matches[2])";
        },
        $sql
    );

    // Replace simple GROUP_CONCAT without ORDER BY
    // Pattern: GROUP_CONCAT(col SEPARATOR ',')
    $sql = preg_replace_callback(
        '/GROUP_CONCAT\s*\(\s*([^S]+)\s+SEPARATOR\s+[\'"]([^\'"]+)[\'"]\s*\)/i',
        function($matches) {
            return "string_agg($matches[1]::text, '$matches[2]')";
        },
        $sql
    );

    // Replace DATABASE() with current_database()
    $sql = preg_replace('/DATABASE\(\)/i', "current_database()", $sql);

    return $sql;
}

/**
 * Cross-database replacement for MySQL's DESCRIBE <table>
 * Returns rows with 'Field' key for both MySQL and PostgreSQL
 */
function describe_table(PDO $conn, string $table): array {
    global $_db_engine;
    try {
        if ($_db_engine === 'pgsql') {
            $stmt = $conn->prepare(
                "SELECT column_name AS \"Field\", data_type AS \"Type\", is_nullable AS \"Null\", '' AS \"Key\", column_default AS \"Default\", '' AS \"Extra\"
                 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ?
                 ORDER BY ordinal_position"
            );
            $stmt->execute([$table]);
        } else {
            $stmt = $conn->query("DESCRIBE `{$table}`");
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Error describing table {$table}: " . $e->getMessage());
        return [];
    }
}

/**
 * Cross-database SQL fragment for comparing time overlap
 * Returns an expression equivalent to ADDTIME(col, SEC_TO_TIME(minutes * 60))
 */
function pgsql_addtime_expr(string $col, string $minutes_expr): string {
    global $_db_engine;
    if ($_db_engine === 'pgsql') {
        return "({$col} + ({$minutes_expr}) * INTERVAL '1 minute')";
    }
    return "ADDTIME({$col}, SEC_TO_TIME({$minutes_expr} * 60))";
}

/**
 * Cross-database TIME_FORMAT equivalent
 */
function pgsql_time_format(string $col): string {
    global $_db_engine;
    if ($_db_engine === 'pgsql') {
        return "TO_CHAR({$col}::time, 'HH24:MI:SS')";
    }
    return "TIME_FORMAT({$col}, '%H:%i:%s')";
}

/**
 * Cross-database DATE_FORMAT equivalent
 */
function pgsql_date_format_expr(string $col, string $format = '%Y-%m-%d'): string {
    global $_db_engine;
    if ($_db_engine === 'pgsql') {
        $pg_format = str_replace(['%Y', '%m', '%d'], ['YYYY', 'MM', 'DD'], $format);
        return "TO_CHAR({$col}::date, '{$pg_format}')";
    }
    return "DATE_FORMAT({$col}, '{$format}')";
}

/**
 * Cross-database DATE_ADD(..., INTERVAL N DAY) equivalent
 */
function pgsql_date_add_days_expr(string $col, int $days): string {
    global $_db_engine;
    if ($_db_engine === 'pgsql') {
        return "({$col}::date + {$days})";
    }
    return "DATE_ADD({$col}, INTERVAL {$days} DAY)";
}

/**
 * Execute a query with PostgreSQL/MySQL compatibility
 * Automatically converts MySQL-specific syntax
 */
function execute_compatible_query($conn, $sql, $params = []) {
    global $_db_engine;
    
    if ($_db_engine === 'pgsql') {
        $sql = convert_sql_for_postgresql($sql);
    }
    
    try {
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    } catch (PDOException $e) {
        error_log("Query Error: " . $e->getMessage() . "\nSQL: $sql");
        throw $e;
    }
}

/**
 * Get table structure info
 * Replaces DESCRIBE for PostgreSQL
 */
function get_table_columns($conn, $table_name) {
    global $_db_engine;
    
    try {
        if ($_db_engine === 'pgsql') {
            $sql = "SELECT column_name, data_type, is_nullable, column_default 
                    FROM information_schema.columns 
                    WHERE table_name = ? AND table_schema = 'public'
                    ORDER BY ordinal_position";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$table_name]);
        } else {
            // MySQL
            $sql = "DESCRIBE $table_name";
            $stmt = $conn->query($sql);
        }
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error getting table columns: " . $e->getMessage());
        return [];
    }
}

/**
 * Check if table exists
 * Replaces information_schema.tables queries for compatibility
 */
function table_exists($conn, $table_name) {
    global $_db_engine;
    
    try {
        if ($_db_engine === 'pgsql') {
            $sql = "SELECT 1 FROM information_schema.tables 
                    WHERE table_schema = 'public' 
                    AND table_name = ?
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$table_name]);
        } else {
            // MySQL
            $sql = "SELECT 1 FROM information_schema.tables 
                    WHERE table_schema = DATABASE() 
                    AND table_name = ?
                    LIMIT 1";
            $stmt = $conn->prepare($sql);
            $stmt->execute([$table_name]);
        }
        return (bool) $stmt->fetchColumn();
    } catch (PDOException $e) {
        error_log("Error checking if table exists: " . $e->getMessage());
        return false;
    }
}

/**
 * Get list of all tables in current database
 */
function get_all_tables($conn) {
    global $_db_engine;
    
    try {
        if ($_db_engine === 'pgsql') {
            $sql = "SELECT table_name FROM information_schema.tables 
                    WHERE table_schema = 'public'
                    ORDER BY table_name";
            $stmt = $conn->query($sql);
        } else {
            // MySQL
            $sql = "SELECT table_name FROM information_schema.tables 
                    WHERE table_schema = DATABASE()
                    ORDER BY table_name";
            $stmt = $conn->query($sql);
        }
        $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return array_column($results, 'table_name');
    } catch (PDOException $e) {
        error_log("Error getting tables: " . $e->getMessage());
        return [];
    }
}

/**
 * Cross-database column existence check.
 * Replaces MySQL-only SHOW COLUMNS FROM queries.
 */
function has_column(PDO $conn, string $table, string $column): bool {
    global $_db_engine;
    try {
        if ($_db_engine === 'pgsql') {
            $stmt = $conn->prepare(
                "SELECT 1 FROM information_schema.columns
                 WHERE table_schema = 'public' AND table_name = ? AND column_name = ? LIMIT 1"
            );
            $stmt->execute([$table, $column]);
        } else {
            $stmt = $conn->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
        }
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        error_log("Error checking column {$table}.{$column}: " . $e->getMessage());
        return false;
    }
}
/**
 * Reset a PostgreSQL SERIAL sequence to match the current max id.
 * No-op on MySQL. Safe to call repeatedly.
 */
function pgsql_fix_serial_sequence(PDO $conn, string $table, string $column = 'id'): void {
    global $_db_engine;
    if ($_db_engine !== 'pgsql') {
        return;
    }
    try {
        $conn->exec("SELECT setval(pg_get_serial_sequence('{$table}', '{$column}'), COALESCE((SELECT MAX({$column}) FROM {$table}), 1), true)");
    } catch (Throwable $e) {
        error_log("Sequence reset warning ({$table}.{$column}): " . $e->getMessage());
    }
}
?>
