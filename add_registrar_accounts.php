<?php
require_once 'config/database.php';

$defaultPassword = 'pct@12345';
$hashedPassword = password_hash($defaultPassword, PASSWORD_DEFAULT);

$registrarAccounts = [
    [
        'first_name' => 'Mary Joy',
        'last_name' => 'Camacho',
        'username' => 'MaryJoyCamacho',
        'email' => 'maryjoycamacho@pct.edu',
    ],
    [
        'first_name' => 'Genife',
        'last_name' => 'Ellaga',
        'username' => 'GenifeEllaga',
        'email' => 'genifeellaga@pct.edu',
    ],
    [
        'first_name' => 'Andebina',
        'last_name' => 'Modina',
        'username' => 'AndebinaModina',
        'email' => 'andebinamodina@pct.edu',
    ],
    [
        'first_name' => 'Jean',
        'last_name' => 'Navarro',
        'username' => 'JeanNavarro',
        'email' => 'jeannavarro@pct.edu',
    ],
    [
        'first_name' => 'Rovanie',
        'last_name' => 'Ngap',
        'username' => 'RovanieNgap',
        'email' => 'roviengap@pct.edu',
    ],
    [
        'first_name' => 'Irene',
        'last_name' => 'Pag-ong',
        'username' => 'IrenePagong',
        'email' => 'irenepagong@pct.edu',
    ],
    [
        'first_name' => 'Joan',
        'last_name' => 'Sausa',
        'username' => 'JoanSausa',
        'email' => 'joansausa@pct.edu',
    ],
    [
        'first_name' => 'Erika',
        'last_name' => 'Peñaranda',
        'username' => 'ErikaPenaranda',
        'email' => 'erikapenaranda@pct.edu',
    ],
];

try {
    $conn->beginTransaction();

    $findByUsernameStmt = $conn->prepare('SELECT id FROM users WHERE username = ? LIMIT 1');
    $insertStmt = $conn->prepare(
        'INSERT INTO users (username, password, email, role, first_name, last_name) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $updateStmt = $conn->prepare(
        'UPDATE users SET password = ?, role = ?, email = ?, first_name = ?, last_name = ? WHERE id = ?'
    );

    foreach ($registrarAccounts as $account) {
        $findByUsernameStmt->execute([$account['username']]);
        $existing = $findByUsernameStmt->fetch(PDO::FETCH_ASSOC);

        if ($existing) {
            $updateStmt->execute([
                $hashedPassword,
                'registrar',
                $account['email'],
                $account['first_name'],
                $account['last_name'],
                $existing['id'],
            ]);

            echo 'Updated existing account: ' . $account['username'] . PHP_EOL;
            continue;
        }

        $insertStmt->execute([
            $account['username'],
            $hashedPassword,
            $account['email'],
            'registrar',
            $account['first_name'],
            $account['last_name'],
        ]);

        echo 'Created account: ' . $account['username'] . PHP_EOL;
    }

    $conn->commit();

    echo PHP_EOL;
    echo 'Done. Default password for newly created accounts: ' . $defaultPassword . PHP_EOL;
} catch (Throwable $e) {
    if ($conn->inTransaction()) {
        $conn->rollBack();
    }

    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
