<?php
declare(strict_types=1);

require_once dirname(__DIR__) . DIRECTORY_SEPARATOR . 'env.php';
loadDcvsEnv();

function fail(string $message): void
{
    fwrite(STDERR, "Error: {$message}\n");
    exit(1);
}

function parseOptions(array $arguments): array
{
    $options = [
        'offset' => 0,
        'limit' => 20,
        'dry_run' => false,
        'restore_soft_deleted' => false,
    ];
    foreach (array_slice($arguments, 1) as $argument) {
        if ($argument === '--dry-run') {
            $options['dry_run'] = true;
            continue;
        }
        if ($argument === '--restore-soft-deleted') {
            $options['restore_soft_deleted'] = true;
            continue;
        }
        if (preg_match('/^--(offset|limit)=(\d+)$/', $argument, $matches)) {
            $options[$matches[1]] = (int)$matches[2];
            continue;
        }
        fail("Unsupported argument: {$argument}. Use --offset=N --limit=N --dry-run --restore-soft-deleted.");
    }

    if ($options['limit'] < 1 || $options['limit'] > 75) {
        fail('The limit must be between 1 and 75.');
    }

    return $options;
}

function loadOfficialBarangays(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        fail("The official barangay crosswalk is missing or unreadable: {$path}");
    }

    $handle = fopen($path, 'rb');
    if ($handle === false) {
        fail("Could not open the official barangay crosswalk: {$path}");
    }

    $header = fgetcsv($handle);
    if (!is_array($header)) {
        fclose($handle);
        fail('The official barangay crosswalk has no header row.');
    }
    $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$header[0]);
    $codeColumn = array_search('PSGC_10_Digit_Code', $header, true);
    $nameColumn = array_search('Official_Barangay_Name', $header, true);
    if ($codeColumn === false || $nameColumn === false) {
        fclose($handle);
        fail('The crosswalk must contain PSGC_10_Digit_Code and Official_Barangay_Name columns.');
    }

    $records = [];
    while (($row = fgetcsv($handle)) !== false) {
        $code = trim((string)($row[$codeColumn] ?? ''));
        $name = trim((string)($row[$nameColumn] ?? ''));
        if ($code === '' || $name === '') {
            fclose($handle);
            fail('The crosswalk contains a row without an official name or PSGC code.');
        }
        if (isset($records[$code])) {
            fclose($handle);
            fail("The crosswalk contains duplicate PSGC code {$code}.");
        }
        $records[$code] = ['code' => $code, 'name' => $name];
    }
    fclose($handle);

    if (count($records) !== 75) {
        fail('Expected exactly 75 official barangays in the crosswalk; found ' . count($records) . '.');
    }

    $records = array_values($records);
    usort($records, static function (array $left, array $right): int {
        return strcasecmp($left['name'], $right['name']);
    });

    return $records;
}

function romanNumber(string $roman): int
{
    static $numbers = [
        'I' => 1,
        'II' => 2,
        'III' => 3,
        'IV' => 4,
        'V' => 5,
        'VI' => 6,
        'VII' => 7,
        'VIII' => 8,
        'IX' => 9,
        'X' => 10,
    ];

    return $numbers[strtoupper($roman)] ?? 0;
}

function normalizeBarangayText(string $text): string
{
    return str_replace(["\u{00F1}", "\u{00D1}"], ['n', 'N'], $text);
}

function credentialFor(string $name): array
{
    $romanSuffix = null;
    if (preg_match('/\s+(X|IX|VIII|VII|VI|V|IV|III|II|I)$/i', $name, $matches)) {
        $romanSuffix = strtoupper($matches[1]);
    }

    $slugSource = normalizeBarangayText($name);
    $slugSource = preg_replace_callback(
        '/(?<![A-Za-z0-9])(VIII|VII|VI|IV|IX|III|II|X|V|I)(?![A-Za-z0-9])/i',
        static function (array $matches): string {
            return (string)romanNumber($matches[1]);
        },
        $slugSource
    );
    $slug = strtolower((string)preg_replace('/[^A-Za-z0-9]/', '', (string)$slugSource));
    if ($slug === '') {
        fail("Could not create a username slug for {$name}.");
    }

    $baseName = $romanSuffix === null
        ? $name
        : preg_replace('/\s+' . preg_quote($romanSuffix, '/') . '$/i', '', $name);
    $baseName = normalizeBarangayText((string)$baseName);
    $baseName = preg_replace('/[^A-Za-z0-9]+/', ' ', $baseName);
    $parts = preg_split('/\s+/', trim((string)$baseName), -1, PREG_SPLIT_NO_EMPTY);
    $passwordName = implode('', array_map(static function (string $part): string {
        return strtoupper(substr($part, 0, 1)) . strtolower(substr($part, 1));
    }, $parts ?: []));
    if ($passwordName === '') {
        fail("Could not create a password name for {$name}.");
    }

    $number = $romanSuffix === null ? 0 : romanNumber($romanSuffix);
    return [
        'username' => $slug . '@gmail.com',
        'password' => $passwordName . sprintf('%02d', $number) . '!',
    ];
}

function findDuplicates(array $records, string $field): array
{
    $groups = [];
    foreach ($records as $record) {
        $groups[$record[$field]][] = $record['name'];
    }

    return array_filter($groups, static function (array $names): bool {
        return count($names) > 1;
    });
}

function reportDuplicates(array $records): void
{
    $duplicateUsernames = findDuplicates($records, 'username');
    $duplicatePasswords = findDuplicates($records, 'password');

    if (!$duplicateUsernames) {
        echo "Duplicate usernames: none\n";
    } else {
        echo "Duplicate usernames:\n";
        foreach ($duplicateUsernames as $username => $names) {
            echo '  ' . $username . ': ' . implode(', ', $names) . "\n";
        }
    }

    if (!$duplicatePasswords) {
        echo "Duplicate passwords: none\n";
    } else {
        echo "Duplicate passwords (password values omitted):\n";
        foreach ($duplicatePasswords as $names) {
            echo '  ' . implode(', ', $names) . "\n";
        }
    }

    if ($duplicateUsernames || $duplicatePasswords) {
        fail('Resolve duplicate credentials before creating this batch.');
    }
}

function getTableColumns(PDO $pdo, string $table = 'users'): array
{
    $statement = $pdo->prepare(
        'SELECT COLUMN_NAME FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);

    return array_fill_keys($statement->fetchAll(PDO::FETCH_COLUMN), true);
}

function tableExists(PDO $pdo, string $table): bool
{
    $statement = $pdo->prepare(
        'SELECT COUNT(*) FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?'
    );
    $statement->execute([$table]);

    return (int)$statement->fetchColumn() > 0;
}

function getPasswordChangeColumn(array $columns): ?string
{
    foreach (['must_change_password', 'force_password_change', 'password_change_required', 'must_reset_password'] as $candidate) {
        if (isset($columns[$candidate])) {
            return $candidate;
        }
    }

    return null;
}

function checkExistingBarangayDuplicates(PDO $pdo): void
{
    $duplicates = $pdo->query(
        "SELECT barangay_id, COUNT(*) AS account_count
         FROM users
         WHERE role = 'barangay' AND barangay_id IS NOT NULL
         GROUP BY barangay_id
         HAVING COUNT(*) > 1"
    )->fetchAll(PDO::FETCH_ASSOC);

    if ($duplicates) {
        $details = array_map(static function (array $row): string {
            return 'barangay_id ' . $row['barangay_id'] . ' (' . $row['account_count'] . ' accounts)';
        }, $duplicates);
        fail('Cannot enforce one Barangay account per barangay: ' . implode(', ', $details) . '.');
    }
}

function uniqueGuardState(PDO $pdo): array
{
    $columnStatement = $pdo->prepare(
        'SELECT GENERATION_EXPRESSION FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?'
    );
    $columnStatement->execute(['users', 'barangay_account_guard_id']);
    $generationExpression = $columnStatement->fetchColumn();
    $hasColumn = $generationExpression !== false;
    if ($hasColumn) {
        $expression = strtolower((string)$generationExpression);
        if (strpos($expression, 'role') === false || strpos($expression, 'barangay_id') === false) {
            fail('The existing barangay_account_guard_id column does not have the expected generated expression.');
        }
    }

    $indexStatement = $pdo->prepare(
        'SELECT NON_UNIQUE, COUNT(*) AS column_count, MAX(COLUMN_NAME) AS column_name
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ?
         GROUP BY NON_UNIQUE'
    );
    $indexStatement->execute(['users', 'uq_users_one_barangay_account']);
    $index = $indexStatement->fetch(PDO::FETCH_ASSOC);
    $hasIndex = is_array($index);
    if ($hasIndex && (
        (int)$index['NON_UNIQUE'] !== 0
        || (int)$index['column_count'] !== 1
        || $index['column_name'] !== 'barangay_account_guard_id'
    )) {
        fail('The existing uq_users_one_barangay_account index does not match the expected unique guard.');
    }

    if ($hasIndex && !$hasColumn) {
        fail('The unique account index exists without its generated guard column.');
    }

    return ['has_column' => $hasColumn, 'has_index' => $hasIndex];
}

function uniqueGuardSql(bool $hasColumn): string
{
    if ($hasColumn) {
        return 'ALTER TABLE users ADD UNIQUE KEY uq_users_one_barangay_account (barangay_account_guard_id);';
    }

    return "ALTER TABLE users\n"
        . "  ADD COLUMN barangay_account_guard_id INT\n"
        . "    GENERATED ALWAYS AS (CASE WHEN role = 'barangay' THEN barangay_id ELSE NULL END) VIRTUAL,\n"
        . "  ADD UNIQUE KEY uq_users_one_barangay_account (barangay_account_guard_id);";
}

function ensureUniqueGuard(PDO $pdo, array $state): void
{
    if ($state['has_index']) {
        return;
    }
    if (!$state['has_column']) {
        $pdo->exec(
            "ALTER TABLE users
             ADD COLUMN barangay_account_guard_id INT
               GENERATED ALWAYS AS (CASE WHEN role = 'barangay' THEN barangay_id ELSE NULL END) VIRTUAL"
        );
    }
    $pdo->exec(
        'ALTER TABLE users ADD UNIQUE KEY uq_users_one_barangay_account (barangay_account_guard_id)'
    );
}

function prepareBatch(
    PDO $pdo,
    array $officialBarangays,
    bool $lockAccounts,
    int $offset,
    int $limit,
    bool $restoreSoftDeleted
): array
{
    $barangayStatement = $pdo->query(
        "SELECT barangay_id, barangay_name, psgc_code
         FROM barangays
         WHERE psgc_code IS NOT NULL AND psgc_code <> ''"
    );
    $barangaysByCode = [];
    foreach ($barangayStatement->fetchAll(PDO::FETCH_ASSOC) as $barangay) {
        $code = trim((string)$barangay['psgc_code']);
        if (isset($barangaysByCode[$code])) {
            fail("Multiple local barangays use PSGC code {$code}.");
        }
        $barangaysByCode[$code] = $barangay;
    }

    $existingStatement = $pdo->query(
        "SELECT user_id, barangay_id, deleted_at
         FROM users
         WHERE role = 'barangay' AND barangay_id IS NOT NULL"
        . ($lockAccounts ? ' FOR UPDATE' : '')
    );
    $existingAccounts = [];
    foreach ($existingStatement->fetchAll(PDO::FETCH_ASSOC) as $account) {
        $existingAccounts[(int)$account['barangay_id']] = $account;
    }

    $eligible = [];
    foreach ($officialBarangays as $official) {
        if (!isset($barangaysByCode[$official['code']])) {
            fail("No local barangay row matches official PSGC code {$official['code']} ({$official['name']}).");
        }
        if (strcasecmp($official['name'], 'Paliparan III') === 0) {
            continue;
        }

        $barangay = $barangaysByCode[$official['code']];
        $barangayId = (int)$barangay['barangay_id'];
        $existingAccount = $existingAccounts[$barangayId] ?? null;
        if ($existingAccount !== null) {
            if (!$restoreSoftDeleted || $existingAccount['deleted_at'] === null) {
                continue;
            }
        }

        $credential = credentialFor($official['name']);
        $eligible[] = [
            'barangay_id' => $barangayId,
            'user_id' => $existingAccount === null ? null : (int)$existingAccount['user_id'],
            'account_action' => $existingAccount === null ? 'create' : 'reactivate',
            'name' => $official['name'],
            'username' => $credential['username'],
            'email' => $credential['username'],
            'password' => $credential['password'],
        ];
    }

    return array_slice($eligible, $offset, $limit);
}

function assertUsernamesAvailable(PDO $pdo, array $batch): void
{
    $newAccountStatement = $pdo->prepare(
        'SELECT user_id FROM users WHERE username = :username OR email = :email LIMIT 1'
    );
    $reactivationStatement = $pdo->prepare(
        'SELECT user_id FROM users
         WHERE (username = :username OR email = :email) AND user_id <> :user_id LIMIT 1'
    );
    foreach ($batch as $record) {
        $parameters = [
            ':username' => $record['username'],
            ':email' => $record['email'],
        ];
        if ($record['account_action'] === 'reactivate') {
            $parameters[':user_id'] = $record['user_id'];
            $reactivationStatement->execute($parameters);
            $conflict = $reactivationStatement->fetchColumn();
        } else {
            $newAccountStatement->execute($parameters);
            $conflict = $newAccountStatement->fetchColumn();
        }
        if ($conflict !== false) {
            fail("The generated username/email for {$record['name']} is already in use.");
        }
    }
}

function showBatchPreview(array $batch): void
{
    echo 'Batch size: ' . count($batch) . "\n";
    echo "Action | Barangay | Username\n";
    foreach ($batch as $record) {
        echo $record['account_action'] . ' | ' . $record['name'] . ' | ' . $record['username'] . "\n";
    }
}

function run(array $arguments): void
{
    $batchOptions = parseOptions($arguments);
    $host = strtolower(trim((string)env('DB_HOST', '127.0.0.1')));
    $port = (int)env('DB_PORT', 3306);
    $database = trim((string)env('DB_NAME', 'dcvs'));
    $username = (string)env('DB_USER', 'root');
    $password = (string)env('DB_PASS', '');

    if (!in_array($host, ['127.0.0.1', 'localhost', '::1'], true)) {
        fail('This script only permits a loopback DB_HOST; no remote/live database is allowed.');
    }
    if ($database !== 'dcvs') {
        fail('This script only permits the local dcvs database.');
    }

    $pdo = new PDO(
        "mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]
    );

    $server = $pdo->query(
        'SELECT DATABASE() AS database_name, @@port AS server_port, @@bind_address AS bind_address, @@datadir AS data_directory'
    )->fetch();
    if (
        $server['database_name'] !== 'dcvs'
        || (int)$server['server_port'] !== $port
        || !in_array(trim((string)$server['bind_address']), ['127.0.0.1', '::1'], true)
    ) {
        fail('Database server verification failed; expected local dcvs bound only to a loopback address.');
    }

    $crosswalkMatches = glob(dirname(__DIR__) . DIRECTORY_SEPARATOR . 'DCVS*75_Barangay_Crosswalk_Template.csv');
    if ($crosswalkMatches === false || count($crosswalkMatches) !== 1) {
        fail('Expected exactly one 75-barangay crosswalk file in the project root.');
    }
    $crosswalkPath = $crosswalkMatches[0];
    $officialBarangays = loadOfficialBarangays($crosswalkPath);
    checkExistingBarangayDuplicates($pdo);
    $guardState = uniqueGuardState($pdo);
    $columns = getTableColumns($pdo);
    $passwordChangeColumn = getPasswordChangeColumn($columns);
    $rememberTokensExist = tableExists($pdo, 'remember_tokens');
    if ($rememberTokensExist && !isset(getTableColumns($pdo, 'remember_tokens')['user_id'])) {
        fail('The remember_tokens table exists but has no user_id column.');
    }

    echo "Target: local database dcvs on {$server['bind_address']}:{$server['server_port']}\n";
    echo "Data directory: {$server['data_directory']}\n";
    echo "Unique Barangay-per-barangay constraint: "
        . ($guardState['has_index'] ? "present\n" : "missing\n");

    if (!$guardState['has_index']) {
        echo "Constraint SQL to be applied on a real run:\n" . uniqueGuardSql($guardState['has_column']) . "\n";
    }
    if ($passwordChangeColumn === null) {
        echo "WARNING: users has no supported must-change-password column. Passwords will not be forced to change at login.\n";
        echo "Suggested SQL: ALTER TABLE users ADD COLUMN must_change_password TINYINT(1) NOT NULL DEFAULT 0;\n";
    } else {
        echo "Password-change flag: {$passwordChangeColumn} (will be set to 1)\n";
    }
    if ($batchOptions['restore_soft_deleted']) {
        echo "Soft-deleted Barangay accounts: eligible rows will be reactivated with new credentials.\n";
    }

    if ($batchOptions['dry_run']) {
        $batch = prepareBatch(
            $pdo,
            $officialBarangays,
            false,
            $batchOptions['offset'],
            $batchOptions['limit'],
            $batchOptions['restore_soft_deleted']
        );
        reportDuplicates($batch);
        assertUsernamesAvailable($pdo, $batch);
        showBatchPreview($batch);
        echo "Dry run only; no accounts or schema were changed.\n";
        return;
    }

    ensureUniqueGuard($pdo, $guardState);
    $pdo->beginTransaction();
    try {
        $batch = prepareBatch(
            $pdo,
            $officialBarangays,
            true,
            $batchOptions['offset'],
            $batchOptions['limit'],
            $batchOptions['restore_soft_deleted']
        );
        reportDuplicates($batch);
        assertUsernamesAvailable($pdo, $batch);

        $columnsToInsert = ['username', 'password', 'email', 'full_name', 'role', 'barangay_id', 'status'];
        if ($passwordChangeColumn !== null) {
            $columnsToInsert[] = $passwordChangeColumn;
        }
        $columnSql = implode(', ', array_map(static function (string $column): string {
            return '`' . $column . '`';
        }, $columnsToInsert));
        $valueSql = implode(', ', array_map(static function (string $column): string {
            return ':' . $column;
        }, $columnsToInsert));
        $insert = $pdo->prepare("INSERT INTO users ({$columnSql}) VALUES ({$valueSql})");
        $updateParts = [
            'username = :username',
            'password = :password',
            'email = :email',
            'full_name = :full_name',
            "status = 'active'",
            'deleted_at = NULL',
        ];
        if (isset($columns['archived_at'])) {
            $updateParts[] = 'archived_at = NULL';
        }
        if (isset($columns['password_updated_at'])) {
            $updateParts[] = 'password_updated_at = NOW()';
        }
        if ($passwordChangeColumn !== null) {
            $updateParts[] = '`' . $passwordChangeColumn . '` = 1';
        }
        $reactivate = $pdo->prepare(
            'UPDATE users SET ' . implode(', ', $updateParts)
            . " WHERE user_id = :user_id AND role = 'barangay' AND deleted_at IS NOT NULL"
        );
        $deleteRememberTokens = $rememberTokensExist
            ? $pdo->prepare('DELETE FROM remember_tokens WHERE user_id = ?')
            : null;

        foreach ($batch as $record) {
            $values = [
                ':username' => $record['username'],
                ':password' => password_hash($record['password'], PASSWORD_DEFAULT),
                ':email' => $record['email'],
                ':full_name' => 'Barangay ' . $record['name'],
            ];
            if ($record['account_action'] === 'reactivate') {
                $values[':user_id'] = $record['user_id'];
                $reactivate->execute($values);
                if ($reactivate->rowCount() !== 1) {
                    fail("Could not reactivate the existing account for {$record['name']}.");
                }
                if ($deleteRememberTokens !== null) {
                    $deleteRememberTokens->execute([$record['user_id']]);
                }
            } else {
                $values[':role'] = 'barangay';
                $values[':barangay_id'] = $record['barangay_id'];
                $values[':status'] = 'active';
                if ($passwordChangeColumn !== null) {
                    $values[':' . $passwordChangeColumn] = 1;
                }
                $insert->execute($values);
            }
        }
        $pdo->commit();
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $exception;
    }

    $createdCount = count(array_filter($batch, static function (array $record): bool {
        return $record['account_action'] === 'create';
    }));
    $reactivatedCount = count($batch) - $createdCount;
    echo "Created {$createdCount} and reactivated {$reactivatedCount} Barangay account(s).\n";
    echo "Action | Barangay | Username/email | Password\n";
    foreach ($batch as $record) {
        echo $record['account_action'] . ' | ' . $record['name'] . ' | '
            . $record['username'] . ' | ' . $record['password'] . "\n";
    }
}

try {
    run($argv);
} catch (Throwable $exception) {
    fwrite(STDERR, 'Error: ' . $exception->getMessage() . "\n");
    exit(1);
}
