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
        return $stmt->rowCount() > 0;
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
?>
