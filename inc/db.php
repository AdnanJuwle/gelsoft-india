<?php
declare(strict_types=1);

/**
 * One PDO connection, opened the first time something asks for it. Pages that
 * never touch the database never open one.
 *
 * Emulation is off so placeholders are real server-side prepares, and errors
 * throw rather than quietly returning false.
 */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $name = (string) cfg('db.name');
    if ($name === '') {
        throw new RuntimeException('No database configured: fill in the db block in inc/config.php.');
    }

    $dsn = sprintf(
        'mysql:host=%s;dbname=%s;charset=%s',
        (string) cfg('db.host', 'localhost'),
        $name,
        (string) cfg('db.charset', 'utf8mb4')
    );

    $pdo = new PDO($dsn, (string) cfg('db.user'), (string) cfg('db.pass'), [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
        PDO::ATTR_STRINGIFY_FETCHES  => false,
    ]);

    /*
     * One clock.
     *
     * bootstrap.php pins PHP to Asia/Kolkata; nothing pinned MySQL, so NOW()
     * was whatever the host was set to. Some deadlines in this app are
     * computed in SQL and tested in PHP (the marketplace inspection window),
     * others are written by PHP and filtered in SQL (the games board), and
     * every one of those crossings is wrong by the gap between the two.
     *
     * Measured on a session forced to UTC, which is the ordinary shared-host
     * default: a 48 hour window set with DATE_ADD(NOW(), INTERVAL 48 HOUR)
     * read back as 42.5 hours in PHP. The buyer loses five and a half hours
     * of their inspection, and nothing anywhere says so.
     *
     * This laptop is Asia/Calcutta, so the two agreed by accident and no test
     * run here could have found it. That is the whole reason it is set
     * explicitly instead of left to the host.
     *
     * The numeric OFFSET, not the name: "SET time_zone = 'Asia/Kolkata'"
     * needs the mysql.time_zone tables populated, which shared hosting often
     * does not do, and then every connection errors. An offset cannot follow
     * daylight saving; India has none, which is what makes this safe here and
     * would not elsewhere.
     */
    /*
     * Never fatal. PDO is in ERRMODE_EXCEPTION and db() is called by every
     * page that touches the database, so an exception here would be the whole
     * site down -- in exchange for a clock. A wrong clock is a bug; a
     * connection that throws on every request is an outage.
     *
     * It also has to be caught rather than left to bubble because `static
     * $pdo` is already assigned above: a throw would leave a connected handle
     * in the static, and the NEXT call would return it early, past this
     * block, with the zone never set. One 500 and then a silently wrong clock
     * is worse than either on its own.
     */
    $offset = date('P');
    try {
        $pdo->exec("SET time_zone = '" . $offset . "'");

        // Checked, not assumed: a host that ignores the statement should say
        // so in the log rather than produce timestamps nobody can explain.
        $got = $pdo->query('SELECT @@session.time_zone')->fetchColumn();
        if ((string) $got !== $offset) {
            error_log('database time zone is ' . var_export($got, true)
                . ', not ' . $offset . ' -- times written by SQL and by PHP will disagree');
        }
    } catch (Throwable $e) {
        error_log('could not set the database time zone: ' . $e->getMessage());
    }

    /*
     * LOCAL ONLY: make this connection behave like the live host's.
     *
     * On the live server a value sent with a query and a '' written in the
     * SQL arrive with DIFFERENT collations (utf8mb4_general_ci vs
     * utf8mb4_unicode_ci), so NULLIF(?, '') fails with "Illegal mix of
     * collations" -- which took down every Save on the admin order page. Here
     * both always match, so no local run could see it.
     *
     * db.simulate_collation in config.local.php sets the connection's
     * collation apart from the parameters', reproducing that exactly, so the
     * whole test suite can be run under the live server's conditions. It is
     * read ONLY when env is 'local'; the live config never has the key, and
     * even if it did, is_local() is false there.
     */
    $simulate = is_local() ? (string) cfg('db.simulate_collation', '') : '';
    if ($simulate !== '' && preg_match('/^utf8mb4_[a-z0-9_]+$/', $simulate)) {
        $pdo->exec("SET collation_connection = '" . $simulate . "'");
    }

    return $pdo;
}

/**
 * '' becomes NULL, decided HERE rather than in SQL.
 *
 * Write `col = ?` with blank_null($value), never `col = NULLIF(?, '')`. On the
 * live host a value sent with a query and a '' typed into the SQL carry
 * different collations (utf8mb4_general_ci vs utf8mb4_unicode_ci), and
 * comparing them is "Illegal mix of collations" -- which made every Save on
 * the admin order page a 500. Any SQL function that compares a placeholder
 * with a string literal has the same trap; this one had four copies.
 */
function blank_null(string $value): ?string
{
    return $value === '' ? null : $value;
}

/**
 * True when the database is both configured and reachable. Pages use this to
 * decide between real rows and the "restocking soon" state, so the site stays
 * up while the database is still empty or down.
 */
function db_ready(): bool
{
    static $ready = null;
    if ($ready !== null) {
        return $ready;
    }
    try {
        db()->query('SELECT 1');
        return $ready = true;
    } catch (Throwable $e) {
        error_log('db unavailable: ' . $e->getMessage());
        return $ready = false;
    }
}

/** Every row. q('SELECT * FROM products WHERE category = ?', ['blaster']) */
function q(string $sql, array $params = []): array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

/** One row, or null. */
function q1(string $sql, array $params = []): ?array
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch();
    return $row === false ? null : $row;
}

/** A write. Returns the number of rows it touched. */
function exec_sql(string $sql, array $params = []): int
{
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    return $stmt->rowCount();
}
