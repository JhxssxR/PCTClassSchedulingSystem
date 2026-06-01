<?php
require_once 'config/database.php';
$tables = ['courses', 'classrooms', 'subjects'];
foreach ($tables as $t) {
    echo "TABLE: $t\n";
    foreach (get_table_columns($conn, $t) as $r) {
        $c = $r['Field'] ?? $r['column_name'] ?? '';
        echo " - $c\n";
    }
}
