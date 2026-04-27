<?php
/**
 * Just4Mega MySQL - Full Stable Version
 * PHP 5.6+
 * MySQL / MariaDB only
 */

ob_start();
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('default_charset', 'UTF-8');
@error_reporting(E_ALL);

if (session_id() === '') {
    @session_start();
}

/* =========================
   HELPERS
========================= */
function e($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function qv($k, $d = '') { return isset($_GET[$k]) ? $_GET[$k] : $d; }
function pv($k, $d = '') { return isset($_POST[$k]) ? $_POST[$k] : $d; }
function db_qident($name) { return '`' . str_replace('`', '``', $name) . '`'; }
function db_is_valid_identifier($name) { return (bool) preg_match('/^[A-Za-z0-9_$]+$/', $name); }

function flash_set($type, $msg) {
    $_SESSION['flash'] = array('type' => $type, 'msg' => $msg);
}
function flash_get() {
    if (!isset($_SESSION['flash'])) return null;
    $x = $_SESSION['flash'];
    unset($_SESSION['flash']);
    return $x;
}
function redirect_to($url) {
    if (!headers_sent()) {
        header('Location: ' . $url);
        exit;
    }
    echo '<script>location.href=' . json_encode($url) . ';</script>';
    exit;
}
function build_url($base, $current, $replace = array()) {
    $q = array();
    foreach ($current as $k => $v) $q[$k] = $v;
    foreach ($replace as $k => $v) {
        if ($v === null) unset($q[$k]);
        else $q[$k] = $v;
    }
    return $base . (empty($q) ? '' : '?' . http_build_query($q));
}
function safe_json_decode($json) {
    $x = json_decode($json, true);
    return is_array($x) ? $x : array();
}

/* =========================
   DEFAULT CONFIG
   kalau diisi -> auto login
========================= */
$db_host = 'localhost';
$db_user = 'pncaggoi_web2';
$db_pass = 'GwdXd_vV~*(O';
$db_name_default = '';

/* =========================
   ENV CHECK
========================= */
$env_err = '';
$env_info = array();

$env_info[] = 'PHP Version: ' . PHP_VERSION;
$env_info[] = 'mysqli available: ' . (function_exists('mysqli_init') ? 'YES' : 'NO');
$env_info[] = 'session active: ' . (session_id() !== '' ? 'YES' : 'NO');
$env_info[] = 'session.save_path: ' . (@ini_get('session.save_path') ? @ini_get('session.save_path') : '(empty)');
$env_info[] = 'mysqli.default_port: ' . (@ini_get('mysqli.default_port') ? @ini_get('mysqli.default_port') : '3306');
$env_info[] = 'mysqli.default_socket: ' . (@ini_get('mysqli.default_socket') ? @ini_get('mysqli.default_socket') : '(empty)');

if (!function_exists('mysqli_init')) {
    $env_err = 'Ekstensi mysqli tidak aktif / tidak tersedia. Aktifkan mysqli di server.';
}

/* =========================
   MYSQL CONNECTION
========================= */
function db_parse_host($host) {
    $socket = null;
    $port = null;
    $hostname = $host;

    if ($host !== '' && ($host[0] === '/' || strpos($host, '.sock') !== false)) {
        $hostname = 'localhost';
        $socket = $host;
    }

    if (strpos($host, ':') !== false && substr_count($host, ':') === 1) {
        list($h, $p) = explode(':', $host, 2);
        if ($p !== '') {
            if (ctype_digit($p)) {
                $hostname = $h;
                $port = (int)$p;
                $socket = null;
            } elseif (strpos($p, '/') !== false || strpos($p, '.sock') !== false) {
                $hostname = ($h !== '' ? $h : 'localhost');
                $socket = $p;
            }
        }
    }

    if ($port === null || $port <= 0) {
        $dp = @ini_get('mysqli.default_port');
        $port = ($dp && ctype_digit((string)$dp)) ? (int)$dp : 3306;
    }

    return array($hostname, $port, $socket);
}

function db_try_connect($host, $user, $pass, $db = null) {
    list($hostname, $port, $socket) = db_parse_host($host);

    $tries = array();
    $tries[] = array($hostname, $port, $socket, 'primary');

    if (strtolower($hostname) === 'localhost') {
        $tries[] = array('127.0.0.1', $port, null, '127.0.0.1');
        if ($socket === null || $socket === '') {
            $iniSock = @ini_get('mysqli.default_socket');
            if ($iniSock) $tries[] = array('localhost', $port, $iniSock, 'default_socket');
            $tries[] = array('localhost', $port, '/var/run/mysqld/mysqld.sock', '/var/run/mysqld/mysqld.sock');
            $tries[] = array('localhost', $port, '/tmp/mysql.sock', '/tmp/mysql.sock');
        }
    }

    $last = false;
    $attempt_log = array();

    foreach ($tries as $t) {
        $m = @mysqli_init();
        if (!$m) {
            $attempt_log[] = 'mysqli_init failed';
            continue;
        }

        @mysqli_options($m, MYSQLI_OPT_CONNECT_TIMEOUT, 6);

        $ok = @mysqli_real_connect(
            $m,
            $t[0],
            $user,
            $pass,
            ($db !== '' ? $db : null),
            (int)$t[1],
            $t[2]
        );

        if ($ok) {
            if (method_exists($m, 'set_charset')) @mysqli_set_charset($m, 'utf8mb4');
            return array(
                'ok' => true,
                'mysqli' => $m,
                'attempt_log' => $attempt_log,
                'used' => $t
            );
        }

        $attempt_log[] = 'Try [' . $t[3] . '] => ' . $m->connect_error . ' (errno: ' . (int)$m->connect_errno . ')';
        $last = $m;
    }

    return array(
        'ok' => false,
        'mysqli' => $last,
        'attempt_log' => $attempt_log,
        'used' => null
    );
}

/* =========================
   DB HELPERS
========================= */
function db_run_query($mysqli, $sql) {
    $result = @$mysqli->query($sql);
    if ($result === false) {
        return array('error' => $mysqli->error, 'sql' => $sql);
    }

    if ($result === true) {
        return array(
            'success' => true,
            'affected' => $mysqli->affected_rows,
            'insert_id' => $mysqli->insert_id,
            'sql' => $sql
        );
    }

    $rows = array();
    while ($row = $result->fetch_assoc()) $rows[] = $row;

    $fields = array();
    $meta = $result->fetch_fields();
    if (is_array($meta)) {
        foreach ($meta as $f) $fields[] = $f->name;
    }

    $result->free();

    return array(
        'rows' => $rows,
        'fields' => $fields,
        'count' => count($rows),
        'sql' => $sql
    );
}

function db_fetch_all_values($mysqli, $sql) {
    $out = array();
    $r = @$mysqli->query($sql);
    if ($r) {
        while ($row = $r->fetch_row()) $out[] = $row[0];
        $r->free();
    }
    return $out;
}

function db_quote($mysqli, $v) {
    if ($v === '__NULL__' || $v === null) return 'NULL';
    return "'" . $mysqli->real_escape_string((string)$v) . "'";
}

function db_get_databases($mysqli) {
    return db_fetch_all_values($mysqli, 'SHOW DATABASES');
}
function db_get_tables($mysqli) {
    return db_fetch_all_values($mysqli, 'SHOW TABLES');
}
function db_get_columns($mysqli, $table) {
    return db_run_query($mysqli, "SHOW FULL COLUMNS FROM " . db_qident($table));
}
function db_get_primary_columns($mysqli, $table) {
    $res = @$mysqli->query("SHOW KEYS FROM " . db_qident($table) . " WHERE Key_name='PRIMARY'");
    $out = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) $out[] = $row['Column_name'];
        $res->free();
    }
    return $out;
}
function db_get_unique_indexes($mysqli, $table) {
    $res = @$mysqli->query("SHOW KEYS FROM " . db_qident($table));
    $out = array();
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            if ((string)$row['Non_unique'] === '0') {
                $key = $row['Key_name'];
                if (!isset($out[$key])) $out[$key] = array();
                $out[$key][] = $row['Column_name'];
            }
        }
        $res->free();
    }
    return $out;
}
function db_get_best_key_columns($mysqli, $table) {
    $pk = db_get_primary_columns($mysqli, $table);
    if (!empty($pk)) return $pk;

    $u = db_get_unique_indexes($mysqli, $table);
    foreach ($u as $name => $cols) {
        if (!empty($cols)) return $cols;
    }

    $cols = db_get_columns($mysqli, $table);
    if (!empty($cols['rows'][0]['Field'])) return array($cols['rows'][0]['Field']);
    return array();
}
function db_build_where_from_key($mysqli, $keys) {
    $parts = array();
    foreach ($keys as $col => $val) {
        if ($val === '__NULL__' || $val === null) $parts[] = db_qident($col) . " IS NULL";
        else $parts[] = db_qident($col) . "=" . db_quote($mysqli, $val);
    }
    return implode(' AND ', $parts);
}
function db_get_row_by_keys($mysqli, $table, $keys) {
    if (empty($keys)) return array('error' => 'No key columns');
    $where = db_build_where_from_key($mysqli, $keys);
    return db_run_query($mysqli, "SELECT * FROM " . db_qident($table) . " WHERE " . $where . " LIMIT 1");
}
function db_create_insert_sql($mysqli, $table, $data) {
    $cols = array();
    $vals = array();
    foreach ($data as $col => $val) {
        $cols[] = db_qident($col);
        $vals[] = db_quote($mysqli, $val);
    }
    return "INSERT INTO " . db_qident($table) . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ")";
}
function db_create_update_sql($mysqli, $table, $data, $keys) {
    $set = array();
    foreach ($data as $col => $val) {
        $set[] = db_qident($col) . "=" . db_quote($mysqli, $val);
    }
    $where = db_build_where_from_key($mysqli, $keys);
    return "UPDATE " . db_qident($table) . " SET " . implode(', ', $set) . " WHERE " . $where . " LIMIT 1";
}
function db_create_delete_sql($mysqli, $table, $keys) {
    $where = db_build_where_from_key($mysqli, $keys);
    return "DELETE FROM " . db_qident($table) . " WHERE " . $where . " LIMIT 1";
}
function db_get_create_table_sql($mysqli, $table) {
    $res = db_run_query($mysqli, "SHOW CREATE TABLE " . db_qident($table));
    if (!empty($res['rows'][0])) {
        $row = $res['rows'][0];
        foreach ($row as $k => $v) {
            if (stripos($k, 'Create Table') !== false) return $v;
        }
        $vals = array_values($row);
        if (isset($vals[1])) return $vals[1];
    }
    return '';
}
function db_export_table_sql($mysqli, $table) {
    $create = db_get_create_table_sql($mysqli, $table);
    $rowsRes = db_run_query($mysqli, "SELECT * FROM " . db_qident($table));

    $sql = "-- Export table: " . $table . "\n";
    $sql .= "-- Generated: " . date('Y-m-d H:i:s') . "\n\n";
    $sql .= "DROP TABLE IF EXISTS " . db_qident($table) . ";\n";
    if ($create !== '') $sql .= $create . ";\n\n";

    if (!empty($rowsRes['rows'])) {
        foreach ($rowsRes['rows'] as $row) {
            $cols = array();
            $vals = array();
            foreach ($row as $col => $val) {
                $cols[] = db_qident($col);
                $vals[] = db_quote($mysqli, $val);
            }
            $sql .= "INSERT INTO " . db_qident($table) . " (" . implode(', ', $cols) . ") VALUES (" . implode(', ', $vals) . ");\n";
        }
    }

    return $sql;
}

/* =========================
   DEFAULT AUTO LOGIN
========================= */
$use_default_auth = ($db_host !== '' && $db_user !== '');
if ($use_default_auth) {
    $_SESSION['db_host'] = $db_host;
    $_SESSION['db_user'] = $db_user;
    $_SESSION['db_pass'] = $db_pass;
}

/* =========================
   MANUAL LOGIN
========================= */
$login_host = $use_default_auth ? $db_host : 'localhost';
$login_user = $use_default_auth ? $db_user : '';
$login_db = $db_name_default;
$login_error_detail = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && pv('action') === 'connect') {
    $try_host = trim(pv('db_host'));
    $try_user = trim(pv('db_user'));
    $try_pass = (string) pv('db_pass');
    $try_db   = trim(pv('db_name'));

    if ($try_host === '' || $try_user === '') {
        $login_error_detail = 'Host dan Username wajib diisi.';
    } else {
        $test = db_try_connect($try_host, $try_user, $try_pass, ($try_db !== '' ? $try_db : null));
        if (!$test['ok']) {
            $m = $test['mysqli'];
            $errno = $m ? (int)$m->connect_errno : 0;
            $errmsg = $m ? (string)$m->connect_error : 'Connection failed';
            $login_error_detail = "Gagal login manual.\n\nError: " . $errmsg . ($errno ? ' (errno: ' . $errno . ')' : '');
            if (!empty($test['attempt_log'])) {
                $login_error_detail .= "\n\nPercobaan koneksi:\n- " . implode("\n- ", $test['attempt_log']);
            }
        } else {
            $_SESSION['db_host'] = $try_host;
            $_SESSION['db_user'] = $try_user;
            $_SESSION['db_pass'] = $try_pass;
            if ($test['mysqli']) @$test['mysqli']->close();

            $go = $_SERVER['PHP_SELF'];
            if ($try_db !== '') $go .= '?db=' . urlencode($try_db);
            flash_set('success', 'Koneksi database berhasil.');
            redirect_to($go);
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && pv('action') === 'disconnect') {
    $_SESSION = array();
    @session_destroy();
    redirect_to($_SERVER['PHP_SELF']);
}

/* =========================
   ACTIVE SESSION
========================= */
$host = !empty($_SESSION['db_host']) ? $_SESSION['db_host'] : '';
$user = !empty($_SESSION['db_user']) ? $_SESSION['db_user'] : '';
$pass = isset($_SESSION['db_pass']) ? $_SESSION['db_pass'] : '';
$need_login = ($host === '' || $user === '');

$db_name = trim(qv('db', $db_name_default));
$view = qv('view', '');
$table = trim(qv('table'));
$page = max(1, (int) qv('page', 1));
$limit = max(1, min(1000, (int) qv('limit', 50)));
$where = trim(qv('where'));
$order = trim(qv('order'));
$self = $_SERVER['PHP_SELF'];

$mysqli = null;
$conn_err = null;
$conn_debug = array();

if (!$need_login && $env_err === '') {
    $conn = db_try_connect($host, $user, $pass, ($db_name !== '' ? $db_name : null));
    if ($conn['ok']) {
        $mysqli = $conn['mysqli'];
    } else {
        $m = $conn['mysqli'];
        $conn_err = $m ? $m->connect_error . ' (errno: ' . (int)$m->connect_errno . ')' : 'Connection failed';
        $conn_debug = $conn['attempt_log'];
        $mysqli = null;
    }
}

/* =========================
   POST ACTIONS
========================= */
if ($mysqli && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = pv('action');

    if ($act === 'insert_row_submit') {
        $tbl = trim(pv('table'));
        $data = safe_json_decode(pv('row_payload'));

        if ($tbl === '') {
            flash_set('error', 'Insert row gagal: table kosong.');
        } else {
            $res = db_run_query($mysqli, db_create_insert_sql($mysqli, $tbl, $data));
            if (!empty($res['error'])) flash_set('error', 'Insert row gagal: ' . $res['error']);
            else flash_set('success', 'Insert row berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name) . '&view=browse&table=' . urlencode($tbl));
    }

    if ($act === 'update_row_submit') {
        $tbl = trim(pv('table'));
        $data = safe_json_decode(pv('row_payload'));
        $keys = safe_json_decode(pv('row_keys'));

        if ($tbl === '' || empty($keys)) {
            flash_set('error', 'Update row gagal: key row tidak ditemukan.');
        } else {
            $res = db_run_query($mysqli, db_create_update_sql($mysqli, $tbl, $data, $keys));
            if (!empty($res['error'])) flash_set('error', 'Update row gagal: ' . $res['error']);
            else flash_set('success', 'Update row berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name) . '&view=browse&table=' . urlencode($tbl));
    }

    if ($act === 'delete_row_submit') {
        $tbl = trim(pv('table'));
        $keys = safe_json_decode(pv('row_keys'));

        if ($tbl === '' || empty($keys)) {
            flash_set('error', 'Delete row gagal: key row tidak ditemukan.');
        } else {
            $res = db_run_query($mysqli, db_create_delete_sql($mysqli, $tbl, $keys));
            if (!empty($res['error'])) flash_set('error', 'Delete row gagal: ' . $res['error']);
            else flash_set('success', 'Delete row berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name) . '&view=browse&table=' . urlencode($tbl));
    }

    if ($act === 'rename_table_submit') {
        $old = trim(pv('old_name'));
        $new = trim(pv('new_name'));

        if ($old === '' || $new === '') {
            flash_set('error', 'Rename table gagal: nama kosong.');
        } elseif (!db_is_valid_identifier($new)) {
            flash_set('error', 'Rename table gagal: nama baru tidak valid.');
        } else {
            $res = db_run_query($mysqli, "RENAME TABLE " . db_qident($old) . " TO " . db_qident($new));
            if (!empty($res['error'])) flash_set('error', 'Rename table gagal: ' . $res['error']);
            else flash_set('success', 'Rename table berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name));
    }

    if ($act === 'add_column_submit') {
        $tbl = trim(pv('table'));
        $name = trim(pv('name'));
        $type = trim(pv('type'));
        $nullable = (int) pv('nullable', 0);
        $default_set = (int) pv('default_set', 0);
        $default_val = pv('default');

        if ($tbl === '' || $name === '' || $type === '') {
            flash_set('error', 'Add column gagal: parameter belum lengkap.');
        } elseif (!db_is_valid_identifier($name)) {
            flash_set('error', 'Add column gagal: nama kolom tidak valid.');
        } else {
            $sql = "ALTER TABLE " . db_qident($tbl) . " ADD COLUMN " . db_qident($name) . " " . $type;
            $sql .= $nullable ? " NULL" : " NOT NULL";
            if ($default_set === 1) {
                if ($default_val === '__NULL__') $sql .= " DEFAULT NULL";
                else $sql .= " DEFAULT " . db_quote($mysqli, $default_val);
            }

            $res = db_run_query($mysqli, $sql);
            if (!empty($res['error'])) flash_set('error', 'Add column gagal: ' . $res['error']);
            else flash_set('success', 'Add column berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name) . '&view=structure&table=' . urlencode($tbl));
    }

    if ($act === 'edit_column_submit') {
        $tbl = trim(pv('table'));
        $old_name = trim(pv('old_name'));
        $new_name = trim(pv('new_name'));
        $type = trim(pv('type'));
        $nullable = (int) pv('nullable', 0);
        $default_set = (int) pv('default_set', 0);
        $default_val = pv('default');
        $extra = trim(pv('extra'));

        if ($tbl === '' || $old_name === '' || $new_name === '' || $type === '') {
            flash_set('error', 'Edit column gagal: parameter belum lengkap.');
        } elseif (!db_is_valid_identifier($new_name)) {
            flash_set('error', 'Edit column gagal: nama kolom baru tidak valid.');
        } else {
            $sql = "ALTER TABLE " . db_qident($tbl) .
                " CHANGE COLUMN " . db_qident($old_name) .
                " " . db_qident($new_name) . " " . $type;
            $sql .= $nullable ? " NULL" : " NOT NULL";
            if ($default_set === 1) {
                if ($default_val === '__NULL__') $sql .= " DEFAULT NULL";
                else $sql .= " DEFAULT " . db_quote($mysqli, $default_val);
            }
            if ($extra !== '') $sql .= " " . $extra;

            $res = db_run_query($mysqli, $sql);
            if (!empty($res['error'])) flash_set('error', 'Edit column gagal: ' . $res['error']);
            else flash_set('success', 'Edit column berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name) . '&view=structure&table=' . urlencode($tbl));
    }

    if ($act === 'drop_column_submit') {
        $tbl = trim(pv('table'));
        $name = trim(pv('name'));

        if ($tbl === '' || $name === '') {
            flash_set('error', 'Drop column gagal: parameter belum lengkap.');
        } else {
            $res = db_run_query($mysqli, "ALTER TABLE " . db_qident($tbl) . " DROP COLUMN " . db_qident($name));
            if (!empty($res['error'])) flash_set('error', 'Drop column gagal: ' . $res['error']);
            else flash_set('success', 'Drop column berhasil.');
        }

        redirect_to($self . '?db=' . urlencode($db_name) . '&view=structure&table=' . urlencode($tbl));
    }

    if ($act === 'create_table_submit') {
        $tbl = trim(pv('table_name'));
        $columns = safe_json_decode(pv('columns_json'));

        if ($tbl === '') {
            flash_set('error', 'Create table gagal: nama tabel wajib diisi.');
        } elseif (!db_is_valid_identifier($tbl)) {
            flash_set('error', 'Create table gagal: nama tabel tidak valid.');
        } elseif (empty($columns)) {
            flash_set('error', 'Create table gagal: kolom belum diisi.');
        } else {
            $defs = array();
            $primary = array();
            $bad = '';

            foreach ($columns as $col) {
                $name = isset($col['name']) ? trim($col['name']) : '';
                $type = isset($col['type']) ? trim($col['type']) : 'VARCHAR(255)';
                $nullable = !empty($col['nullable']);
                $primary_key = !empty($col['primary']);
                $auto_increment = !empty($col['auto_increment']);
                $default_set = isset($col['default_set']) ? (int)$col['default_set'] : 0;
                $default_val = isset($col['default']) ? $col['default'] : '';

                if ($name === '' || !db_is_valid_identifier($name)) {
                    $bad = 'Nama kolom tidak valid: ' . $name;
                    break;
                }

                $def = db_qident($name) . ' ' . $type;
                $def .= $nullable ? ' NULL' : ' NOT NULL';

                if ($default_set === 1) {
                    if ($default_val === '__NULL__') $def .= ' DEFAULT NULL';
                    else $def .= ' DEFAULT ' . db_quote($mysqli, $default_val);
                }

                if ($auto_increment) $def .= ' AUTO_INCREMENT';
                $defs[] = $def;

                if ($primary_key) $primary[] = db_qident($name);
            }

            if ($bad !== '') {
                flash_set('error', 'Create table gagal: ' . $bad);
            } else {
                if (!empty($primary)) $defs[] = 'PRIMARY KEY (' . implode(', ', $primary) . ')';
                $sql = "CREATE TABLE " . db_qident($tbl) . " (\n  " . implode(",\n  ", $defs) . "\n) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
                $res = db_run_query($mysqli, $sql);
                if (!empty($res['error'])) flash_set('error', 'Create table gagal: ' . $res['error']);
                else flash_set('success', 'Create table berhasil.');
            }
        }

        redirect_to($self . '?db=' . urlencode($db_name));
    }
}

$flash = flash_get();

/* =========================
   PAGE DATA
========================= */
$databases = array();
$tables = array();
$currentBrowse = array();
$currentKeyColumns = array();
$currentTotal = 0;
$currentPages = 1;
$currentInsertCols = array();
$currentEditRow = array();
$currentStructure = array();
$currentIndexes = array();
$currentCreateSQL = '';
$currentExportSql = '';

if ($mysqli) {
    $databases = db_get_databases($mysqli);
    if ($db_name !== '') $tables = db_get_tables($mysqli);
}

if ($mysqli && $db_name !== '' && $table !== '') {
    if ($view === '' || $view === 'browse') {
        $base = " FROM " . db_qident($table);
        if ($where !== '') $base .= " WHERE " . $where;

        $countRes = db_run_query($mysqli, "SELECT COUNT(*) AS n" . $base);
        if (empty($countRes['error'])) {
            $currentTotal = isset($countRes['rows'][0]['n']) ? (int)$countRes['rows'][0]['n'] : 0;
            $currentPages = max(1, (int) ceil($currentTotal / $limit));
        }

        if ($page > $currentPages) $page = $currentPages;
        $offset = ($page - 1) * $limit;

        $sql = "SELECT *" . $base;
        if ($order !== '') $sql .= " ORDER BY " . $order;
        $sql .= " LIMIT " . $limit . " OFFSET " . $offset;

        $currentBrowse = db_run_query($mysqli, $sql);
        $currentKeyColumns = db_get_best_key_columns($mysqli, $table);
    }

    if ($view === 'edit_row') {
        $keys = safe_json_decode(qv('keys_json'));
        $rowRes = db_get_row_by_keys($mysqli, $table, $keys);
        if (!empty($rowRes['rows'][0])) $currentEditRow = $rowRes['rows'][0];
        $resCols = db_get_columns($mysqli, $table);
        $currentInsertCols = !empty($resCols['rows']) ? $resCols['rows'] : array();
    }

    if ($view === 'insert_row') {
        $resCols = db_get_columns($mysqli, $table);
        $currentInsertCols = !empty($resCols['rows']) ? $resCols['rows'] : array();
    }

    if ($view === 'structure' || $view === 'edit_column') {
        $resCols = db_get_columns($mysqli, $table);
        $resIdx = db_run_query($mysqli, "SHOW INDEX FROM " . db_qident($table));
        $currentStructure = !empty($resCols['rows']) ? $resCols['rows'] : array();
        $currentIndexes = !empty($resIdx['rows']) ? $resIdx['rows'] : array();
        $currentCreateSQL = db_get_create_table_sql($mysqli, $table);
    }

    if ($view === 'export') {
        $currentExportSql = db_export_table_sql($mysqli, $table);
    }
}
?>
<!DOCTYPE html>
<html lang="id">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex, nofollow">
<title>Just4Mega MySQL<?php echo $db_name !== '' ? ' - ' . e($db_name) : ''; ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:wght@300;400;500;600&family=Syne:wght@400;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#030507; --bg2:#080b10; --bg3:#0d1219; --line:#202733;
  --text:#e6eeff; --muted:#8fa0bf; --primary:#6f95ff; --green:#4ade80; --red:#ff6b6b; --yellow:#E3B43D;
  --mono:'JetBrains Mono', monospace; --display:'Syne', sans-serif;
}
*{box-sizing:border-box;margin:0;padding:0}
html,body{height:100%}
body{background:var(--bg);color:var(--text);font-family:var(--mono);font-size:13px;line-height:1.55}
a{color:var(--primary);text-decoration:none}
a:hover{text-decoration:underline}
.layout{display:flex;height:100vh;overflow:hidden}
.sidebar{width:270px;min-width:270px;background:var(--bg2);border-right:1px solid var(--line);display:flex;flex-direction:column}
.sidebar-logo{padding:18px;border-bottom:1px solid var(--line);font-family:var(--display);font-size:18px;font-weight:800;color:#fff}
.sidebar-logo span{color:var(--primary)}
.sidebar-items{overflow:auto;flex:1}
.sidebar-section{font-size:10px;font-weight:700;letter-spacing:1.2px;text-transform:uppercase;color:var(--muted);padding:14px 18px 8px}
.sidebar-search{padding:0 18px 10px}
.sidebar-search input{width:100%;background:#020304;border:1px solid var(--line);border-radius:10px;padding:8px 10px;color:var(--text);font-family:var(--mono);font-size:12px}
.sidebar-item{display:flex;align-items:center;gap:9px;padding:9px 18px;color:var(--text);border-left:2px solid transparent}
.sidebar-item:hover{text-decoration:none;background:var(--bg3)}
.sidebar-item.active{background:var(--bg3);border-left-color:var(--primary)}
.dot{width:8px;height:8px;border-radius:50%;flex-shrink:0}
.dot.db{background:var(--green)}
.dot.tbl{background:var(--yellow)}
.sidebar-conn{padding:12px 18px;border-top:1px solid var(--line);color:var(--muted);font-size:11px}
.sidebar-conn .host{color:var(--primary);margin-top:4px;word-break:break-all}
.sidebar-conn .disc{margin-top:8px;background:none;border:none;color:var(--red);font-family:var(--mono);font-size:11px;cursor:pointer}
.main{flex:1;display:flex;flex-direction:column;overflow:hidden}
.topbar{min-height:52px;background:var(--bg2);border-bottom:1px solid var(--line);display:flex;align-items:center;gap:16px;padding:0 20px}
.breadcrumb{font-size:12px;color:var(--muted)}
.topbar-actions{margin-left:auto;display:flex;gap:8px;flex-wrap:wrap}
.content{flex:1;overflow:auto;padding:22px}
.card{background:var(--bg2);border:1px solid var(--line);border-radius:12px;overflow:hidden}
.card-header{padding:12px 16px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:10px;font-weight:700;color:#fff}
.card-body{padding:16px}
.btn,.btn-link{display:inline-flex;align-items:center;gap:7px;padding:7px 12px;border-radius:10px;border:none;cursor:pointer;font-family:var(--mono);font-size:12px;font-weight:600}
.btn-link{text-decoration:none}
.btn-success{background:var(--green);color:#04120a}
.btn-danger{background:var(--red);color:#fff}
.btn-warning{background:var(--yellow);color:#2c1a00}
.btn-sm{padding:5px 10px;font-size:11px}
.form-group{margin-bottom:14px}
.form-label{display:block;margin-bottom:6px;font-size:11px;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:.6px}
.form-control{width:100%;background:#020304;border:1px solid var(--line);border-radius:10px;padding:9px 12px;color:var(--text);font-family:var(--mono);font-size:13px}
textarea.form-control{resize:vertical;min-height:110px}
.tbl-wrap{overflow:auto}
table{width:100%;border-collapse:collapse}
thead th{background:var(--bg3);padding:10px 12px;text-align:left;font-size:11px;font-weight:700;color:var(--muted);border-bottom:1px solid var(--line);text-transform:uppercase;white-space:nowrap}
tbody td{padding:9px 12px;border-bottom:1px solid var(--line);font-size:12px;max-width:340px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;vertical-align:middle}
.msg{padding:10px 14px;border-radius:10px;font-size:12px;margin-bottom:14px}
.msg-success{background:rgba(74,222,128,.08);border:1px solid rgba(74,222,128,.25);color:var(--green)}
.msg-error{background:rgba(255,107,107,.08);border:1px solid rgba(255,107,107,.25);color:var(--red)}
.msg-info{background:rgba(111,149,255,.08);border:1px solid rgba(111,149,255,.25);color:var(--primary)}
pre.code-out{background:#020304;border:1px solid var(--line);border-radius:10px;padding:14px;overflow:auto;font-size:12px;line-height:1.7;color:var(--text);white-space:pre-wrap;word-break:break-word}
.row{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.spacer{flex:1}
.login-screen{min-height:100vh;display:flex;align-items:center;justify-content:center;background-image:radial-gradient(ellipse 80% 60% at 50% -20%, rgba(111,149,255,.12), transparent)}
.login-box{width:100%;max-width:700px;padding:0 20px}
.login-logo{text-align:center;margin-bottom:32px;font-family:var(--display);font-weight:800;font-size:28px;color:#fff}
.login-logo span{color:var(--primary)}
.diag-list{margin-top:10px;padding-left:18px;color:var(--muted);font-size:11px}
.diag-list li{margin:4px 0}
.pagination{display:flex;gap:4px;align-items:center;margin-top:12px;flex-wrap:wrap}
.pg-btn{min-width:30px;height:30px;display:flex;align-items:center;justify-content:center;border-radius:8px;background:var(--bg3);border:1px solid var(--line);color:var(--text);text-decoration:none;font-size:11px}
.pg-btn.active{background:var(--primary);border-color:var(--primary);color:#fff}
.null-val{color:var(--muted);font-style:italic}
</style>
</head>
<body>

<?php if ($need_login || $conn_err || $env_err !== ''): ?>
<div class="login-screen">
  <div class="login-box">
    <div class="login-logo">Just4Mega<span>DB</span></div>

    <?php if ($flash): ?>
      <div class="msg <?= $flash['type'] === 'success' ? 'msg-success' : 'msg-error' ?>"><?= e($flash['msg']) ?></div>
    <?php endif; ?>

    <?php if ($env_err !== ''): ?>
      <div class="msg msg-error"><?= e($env_err) ?></div>
    <?php endif; ?>

    <?php if ($conn_err): ?>
      <div class="msg msg-error" style="white-space:pre-wrap">
Koneksi database gagal:
<?= e($conn_err) ?>

<?php if (!empty($conn_debug)): ?>
Percobaan:
- <?= e(implode("\n- ", $conn_debug)) ?>
<?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($login_error_detail !== ''): ?>
      <div class="msg msg-error" style="white-space:pre-wrap"><?= e($login_error_detail) ?></div>
    <?php endif; ?>

    <?php if (!$use_default_auth): ?>
      <div class="card">
        <div class="card-header">Connect to Database</div>
        <div class="card-body">
          <form method="POST">
            <input type="hidden" name="action" value="connect">
            <div class="form-group">
              <label class="form-label">Host</label>
              <input type="text" name="db_host" class="form-control" value="<?= e($login_host) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Username</label>
              <input type="text" name="db_user" class="form-control" value="<?= e($login_user) ?>" required>
            </div>
            <div class="form-group">
              <label class="form-label">Password</label>
              <input type="password" name="db_pass" class="form-control">
            </div>
            <div class="form-group">
              <label class="form-label">Database (optional)</label>
              <input type="text" name="db_name" class="form-control" value="<?= e($login_db) ?>">
            </div>
            <button type="submit" class="btn btn-warning">Connect</button>
          </form>
        </div>
      </div>
    <?php else: ?>
      <div class="card">
        <div class="card-header">Default Config Auto Login</div>
        <div class="card-body">
          <div class="msg msg-info" style="margin-bottom:0">
            Script mencoba login otomatis memakai config default:
            <br><br>
            Host: <strong><?= e($db_host) ?></strong><br>
            Username: <strong><?= e($db_user) ?></strong><br>
            Database default: <strong><?= e($db_name_default !== '' ? $db_name_default : '(none)') ?></strong>
          </div>
        </div>
      </div>
    <?php endif; ?>

    <ul class="diag-list">
      <?php foreach ($env_info as $line): ?>
        <li><?= e($line) ?></li>
      <?php endforeach; ?>
    </ul>
  </div>
</div>
<?php else: ?>

<div class="layout">
  <aside class="sidebar">
    <div class="sidebar-logo">Just4Mega<span>DB</span></div>
    <div class="sidebar-items">
      <div class="sidebar-section">Databases</div>
      <div class="sidebar-search"><input type="text" id="db-search" placeholder="Search databases"></div>
      <div id="db-list">
        <?php foreach ($databases as $dbn): ?>
          <a data-name="<?= e(strtolower($dbn)) ?>" href="<?= e($self) ?>?db=<?= urlencode($dbn) ?>" class="sidebar-item <?= ($db_name === $dbn ? 'active' : '') ?>">
            <span class="dot db"></span><span><?= e($dbn) ?></span>
          </a>
        <?php endforeach; ?>
      </div>

      <?php if ($db_name !== '' && count($tables) > 0): ?>
        <div class="sidebar-section">Tables - <?= e($db_name) ?></div>
        <div class="sidebar-search"><input type="text" id="table-search" placeholder="Search tables"></div>
        <div id="table-list">
          <?php foreach ($tables as $tbl): ?>
            <a data-name="<?= e(strtolower($tbl)) ?>" href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'browse','table'=>$tbl,'page'=>1,'where'=>null,'order'=>null))) ?>" class="sidebar-item <?= ($table === $tbl ? 'active' : '') ?>">
              <span class="dot tbl"></span><span><?= e($tbl) ?></span>
            </a>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <div class="sidebar-conn">
      <div>Connected as</div>
      <div class="host"><?= e($user) ?>@<?= e($host) ?></div>
      <form method="POST" style="margin:0">
        <input type="hidden" name="action" value="disconnect">
        <button type="submit" class="disc">Disconnect</button>
      </form>
    </div>
  </aside>

  <div class="main">
    <div class="topbar">
      <div class="breadcrumb">
        <a href="<?= e($self) ?>">Databases</a>
        <?php if ($db_name !== ''): ?>&nbsp;>&nbsp;<a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>null,'table'=>null,'page'=>null,'where'=>null,'order'=>null))) ?>"><?= e($db_name) ?></a><?php endif; ?>
        <?php if ($table !== ''): ?>&nbsp;>&nbsp;<span style="color:#fff"><?= e($table) ?></span><?php endif; ?>
      </div>
      <div class="topbar-actions">
        <?php if ($db_name !== ''): ?>
          <a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'create_table','table'=>null,'page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Create Table</a>
        <?php endif; ?>
      </div>
    </div>

    <div class="content">
      <?php if ($flash): ?>
        <div class="msg <?= $flash['type'] === 'success' ? 'msg-success' : 'msg-error' ?>"><?= e($flash['msg']) ?></div>
      <?php endif; ?>

      <?php if ($db_name === ''): ?>

        <div class="card">
          <div class="card-header">All Databases</div>
          <div class="tbl-wrap">
            <table>
              <thead><tr><th>Name</th><th>Action</th></tr></thead>
              <tbody>
                <?php foreach ($databases as $dbn): ?>
                  <tr>
                    <td><?= e($dbn) ?></td>
                    <td><a href="<?= e($self) ?>?db=<?= urlencode($dbn) ?>" class="btn-link btn-warning btn-sm">Open</a></td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($databases)): ?>
                  <tr><td colspan="2">No databases found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php elseif ($view === 'create_table'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('view'=>null,'table'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Create Table</h3>
        </div>

        <div class="card">
          <div class="card-header">Create Table - <?= e($db_name) ?></div>
          <div class="card-body">
            <form method="POST" id="create-table-form">
              <input type="hidden" name="action" value="create_table_submit">
              <input type="hidden" name="columns_json" id="columns_json" value="[]">

              <div class="form-group">
                <label class="form-label">Table Name</label>
                <input type="text" name="table_name" class="form-control" placeholder="new_table" required>
              </div>

              <div id="column-builder"></div>

              <div class="row" style="margin-top:10px">
                <button type="button" class="btn btn-warning" onclick="addCreateColumn()">Add Column</button>
                <button type="button" class="btn btn-warning" onclick="submitCreateTable()">Create</button>
              </div>
            </form>
          </div>
        </div>

      <?php elseif ($table === ''): ?>

        <div class="card">
          <div class="card-header">Tables in <?= e($db_name) ?></div>
          <div class="tbl-wrap">
            <table>
              <thead><tr><th>Table</th><th>Actions</th></tr></thead>
              <tbody>
                <?php foreach ($tables as $tbl): ?>
                  <tr>
                    <td><?= e($tbl) ?></td>
                    <td>
                      <div class="row">
                        <a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'browse','table'=>$tbl,'page'=>1,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Browse</a>
                        <a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'insert_row','table'=>$tbl,'page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Insert</a>
                        <a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'structure','table'=>$tbl,'page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Structure</a>
                        <a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'export','table'=>$tbl,'page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Export</a>
                        <a href="<?= e(build_url($self, $_GET, array('db'=>$db_name,'view'=>'rename_table','table'=>$tbl,'page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Rename</a>
                      </div>
                    </td>
                  </tr>
                <?php endforeach; ?>
                <?php if (empty($tables)): ?>
                  <tr><td colspan="2">No tables found.</td></tr>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

      <?php elseif ($view === 'rename_table'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('table'=>null,'view'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Rename Table</h3>
        </div>

        <div class="card">
          <div class="card-header">Rename Table - <?= e($table) ?></div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="rename_table_submit">
              <input type="hidden" name="old_name" value="<?= e($table) ?>">

              <div class="form-group">
                <label class="form-label">Old Name</label>
                <input type="text" class="form-control" value="<?= e($table) ?>" disabled>
              </div>

              <div class="form-group">
                <label class="form-label">New Name</label>
                <input type="text" name="new_name" class="form-control" value="<?= e($table) ?>" required>
              </div>

              <button type="submit" class="btn btn-warning">Save Rename</button>
            </form>
          </div>
        </div>

      <?php elseif ($view === 'insert_row'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('view'=>'browse','table'=>$table))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Insert Row - <?= e($table) ?></h3>
        </div>

        <div class="card">
          <div class="card-header">Insert Row</div>
          <div class="card-body">
            <form method="POST" onsubmit="return buildRowPayloadForInsert();">
              <input type="hidden" name="action" value="insert_row_submit">
              <input type="hidden" name="table" value="<?= e($table) ?>">
              <input type="hidden" name="row_payload" id="insert_row_payload" value="{}">

              <?php foreach ($currentInsertCols as $c): ?>
                <div class="form-group">
                  <label class="form-label"><?= e($c['Field']) ?> (<?= e($c['Type']) ?>)</label>

                  <?php if (stripos((string)$c['Extra'], 'auto_increment') !== false): ?>
                    <input type="text" class="form-control" value="[AUTO_INCREMENT]" disabled>
                  <?php else: ?>
                    <textarea class="form-control insert-col" data-col="<?= e($c['Field']) ?>"></textarea>
                    <?php if (strtoupper((string)$c['Null']) === 'YES'): ?>
                      <div style="margin-top:8px"><label><input type="checkbox" class="insert-null" data-col="<?= e($c['Field']) ?>"> Set NULL</label></div>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              <?php endforeach; ?>

              <button type="submit" class="btn btn-warning">Save Insert</button>
            </form>
          </div>
        </div>

      <?php elseif ($view === 'edit_row'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('view'=>'browse','table'=>$table,'keys_json'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Edit Row - <?= e($table) ?></h3>
        </div>

        <?php if (empty($currentEditRow)): ?>
          <div class="msg msg-error">Row tidak ditemukan.</div>
        <?php else: ?>
          <div class="card">
            <div class="card-header">Edit Row</div>
            <div class="card-body">
              <form method="POST" onsubmit="return buildRowPayloadForEdit();">
                <input type="hidden" name="action" value="update_row_submit">
                <input type="hidden" name="table" value="<?= e($table) ?>">
                <input type="hidden" name="row_payload" id="edit_row_payload" value="{}">
                <input type="hidden" name="row_keys" value="<?= e(qv('keys_json')) ?>">

                <?php foreach ($currentInsertCols as $c): $field = $c['Field']; $val = isset($currentEditRow[$field]) ? $currentEditRow[$field] : null; ?>
                  <div class="form-group">
                    <label class="form-label"><?= e($field) ?> (<?= e($c['Type']) ?>)</label>
                    <textarea class="form-control edit-col" data-col="<?= e($field) ?>"><?= $val === null ? '' : e($val) ?></textarea>
                    <?php if (strtoupper((string)$c['Null']) === 'YES'): ?>
                      <div style="margin-top:8px"><label><input type="checkbox" class="edit-null" data-col="<?= e($field) ?>" <?= ($val === null ? 'checked' : '') ?>> Set NULL</label></div>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>

                <button type="submit" class="btn btn-warning">Save Update</button>
              </form>
            </div>
          </div>
        <?php endif; ?>

      <?php elseif ($view === 'structure'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('table'=>null,'view'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Structure - <?= e($table) ?></h3>
          <div class="spacer"></div>
          <a href="<?= e(build_url($self, $_GET, array('view'=>'add_column','table'=>$table))) ?>" class="btn-link btn-warning btn-sm">Add Column</a>
        </div>

        <div class="card" style="margin-bottom:12px">
          <div class="card-header">Columns</div>
          <div class="tbl-wrap">
            <table>
              <thead><tr><th>Field</th><th>Type</th><th>Null</th><th>Key</th><th>Default</th><th>Extra</th><th>Actions</th></tr></thead>
              <tbody>
                <?php if (empty($currentStructure)): ?>
                  <tr><td colspan="7">No columns.</td></tr>
                <?php else: ?>
                  <?php foreach ($currentStructure as $c): ?>
                    <tr>
                      <td><?= e($c['Field']) ?></td>
                      <td><?= e($c['Type']) ?></td>
                      <td><?= e($c['Null']) ?></td>
                      <td><?= e($c['Key']) ?></td>
                      <td><?php if ($c['Default'] === null): ?><span class="null-val">NULL</span><?php else: ?><?= e($c['Default']) ?><?php endif; ?></td>
                      <td><?= e($c['Extra']) ?></td>
                      <td>
                        <div class="row">
                          <a href="<?= e(build_url($self, $_GET, array('view'=>'edit_column','column'=>$c['Field']))) ?>" class="btn-link btn-warning btn-sm">Edit</a>

                          <form method="POST" style="display:inline" onsubmit="return confirm('Drop column <?= e($c['Field']) ?> ?');">
                            <input type="hidden" name="action" value="drop_column_submit">
                            <input type="hidden" name="table" value="<?= e($table) ?>">
                            <input type="hidden" name="name" value="<?= e($c['Field']) ?>">
                            <button type="submit" class="btn btn-danger btn-sm">Drop</button>
                          </form>
                        </div>
                      </td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card" style="margin-bottom:12px">
          <div class="card-header">Indexes</div>
          <div class="tbl-wrap">
            <table>
              <thead><tr><th>Key Name</th><th>Column</th><th>Seq</th><th>Unique</th><th>Type</th></tr></thead>
              <tbody>
                <?php if (empty($currentIndexes)): ?>
                  <tr><td colspan="5">No indexes.</td></tr>
                <?php else: ?>
                  <?php foreach ($currentIndexes as $x): ?>
                    <tr>
                      <td><?= e($x['Key_name']) ?></td>
                      <td><?= e($x['Column_name']) ?></td>
                      <td><?= e($x['Seq_in_index']) ?></td>
                      <td><?= ((string)$x['Non_unique'] === '0' ? 'YES' : 'NO') ?></td>
                      <td><?= e($x['Index_type']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                <?php endif; ?>
              </tbody>
            </table>
          </div>
        </div>

        <div class="card">
          <div class="card-header">SHOW CREATE TABLE</div>
          <div class="card-body"><pre class="code-out"><?= e($currentCreateSQL) ?></pre></div>
        </div>

      <?php elseif ($view === 'add_column'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('view'=>'structure'))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Add Column - <?= e($table) ?></h3>
        </div>

        <div class="card">
          <div class="card-header">Add Column</div>
          <div class="card-body">
            <form method="POST">
              <input type="hidden" name="action" value="add_column_submit">
              <input type="hidden" name="table" value="<?= e($table) ?>">

              <div class="form-group">
                <label class="form-label">Column Name</label>
                <input type="text" name="name" class="form-control" required>
              </div>

              <div class="form-group">
                <label class="form-label">Type</label>
                <input type="text" name="type" class="form-control" value="VARCHAR(255)" required>
              </div>

              <div class="form-group">
                <label class="form-label">Default</label>
                <input type="text" name="default" class="form-control">
              </div>

              <div class="row" style="margin-bottom:12px">
                <label><input type="checkbox" name="nullable" value="1" checked> Nullable</label>
                <label><input type="checkbox" name="default_set" value="1"> Use Default</label>
              </div>

              <button type="submit" class="btn btn-warning">Save Add Column</button>
            </form>
          </div>
        </div>

      <?php elseif ($view === 'edit_column'): ?>

        <?php
        $column_name = qv('column');
        $edit_col = array();
        foreach ($currentStructure as $tmp) {
            if ($tmp['Field'] === $column_name) { $edit_col = $tmp; break; }
        }
        ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('view'=>'structure','column'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Edit Column - <?= e($column_name) ?></h3>
        </div>

        <?php if (empty($edit_col)): ?>
          <div class="msg msg-error">Column tidak ditemukan.</div>
        <?php else: ?>
          <div class="card">
            <div class="card-header">Edit Column</div>
            <div class="card-body">
              <form method="POST">
                <input type="hidden" name="action" value="edit_column_submit">
                <input type="hidden" name="table" value="<?= e($table) ?>">
                <input type="hidden" name="old_name" value="<?= e($edit_col['Field']) ?>">
                <input type="hidden" name="extra" value="<?= e($edit_col['Extra']) ?>">

                <div class="form-group">
                  <label class="form-label">Old Name</label>
                  <input type="text" class="form-control" value="<?= e($edit_col['Field']) ?>" disabled>
                </div>

                <div class="form-group">
                  <label class="form-label">New Name</label>
                  <input type="text" name="new_name" class="form-control" value="<?= e($edit_col['Field']) ?>" required>
                </div>

                <div class="form-group">
                  <label class="form-label">Type</label>
                  <input type="text" name="type" class="form-control" value="<?= e($edit_col['Type']) ?>" required>
                </div>

                <div class="form-group">
                  <label class="form-label">Default</label>
                  <input type="text" name="default" class="form-control" value="<?= ($edit_col['Default'] === null ? '' : e($edit_col['Default'])) ?>">
                </div>

                <div class="row" style="margin-bottom:12px">
                  <label><input type="checkbox" name="nullable" value="1" <?= (strtoupper((string)$edit_col['Null']) === 'YES' ? 'checked' : '') ?>> Nullable</label>
                  <label><input type="checkbox" name="default_set" value="1" <?= ($edit_col['Default'] !== null ? 'checked' : '') ?>> Use Default</label>
                </div>

                <button type="submit" class="btn btn-warning">Save Edit Column</button>
              </form>
            </div>
          </div>
        <?php endif; ?>

      <?php elseif ($view === 'export'): ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('table'=>null,'view'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff">Export SQL - <?= e($table) ?></h3>
        </div>

        <div class="card">
          <div class="card-header">Export SQL</div>
          <div class="card-body">
            <pre class="code-out"><?= e($currentExportSql) ?></pre>
          </div>
        </div>

      <?php else: ?>

        <div class="row" style="margin-bottom:12px">
          <a href="<?= e(build_url($self, $_GET, array('table'=>null,'view'=>null,'page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Back</a>
          <h3 style="font-size:14px;color:#fff"><?= e($table) ?></h3>
          <div class="spacer"></div>
          <div class="row">
            <a href="<?= e(build_url($self, $_GET, array('view'=>'browse','page'=>1))) ?>" class="btn-link btn-warning btn-sm">Browse</a>
            <a href="<?= e(build_url($self, $_GET, array('view'=>'insert_row','page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Insert Row</a>
            <a href="<?= e(build_url($self, $_GET, array('view'=>'structure','page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Structure</a>
            <a href="<?= e(build_url($self, $_GET, array('view'=>'export','page'=>null,'where'=>null,'order'=>null))) ?>" class="btn-link btn-warning btn-sm">Export SQL</a>
          </div>
        </div>

        <div class="row" style="margin-bottom:12px">
          <form method="GET" class="row" style="width:100%">
            <input type="hidden" name="db" value="<?= e($db_name) ?>">
            <input type="hidden" name="view" value="browse">
            <input type="hidden" name="table" value="<?= e($table) ?>">
            <input type="text" class="form-control" name="where" value="<?= e($where) ?>" placeholder="WHERE clause contoh: id = 1" style="max-width:360px">
            <input type="text" class="form-control" name="order" value="<?= e($order) ?>" placeholder="ORDER BY contoh: id DESC" style="max-width:240px">
            <input type="number" class="form-control" name="limit" value="<?= e($limit) ?>" min="1" max="1000" style="max-width:90px">
            <button type="submit" class="btn btn-warning btn-sm">Apply</button>
            <a href="<?= e(build_url($self, $_GET, array('view'=>'browse','where'=>null,'order'=>null,'page'=>1,'limit'=>50))) ?>" class="btn-link btn-warning btn-sm">Reset</a>
          </form>
        </div>

        <?php if (!empty($currentBrowse['error'])): ?>
          <div class="msg msg-error"><?= e($currentBrowse['error']) ?></div>
        <?php else: ?>
          <div class="row" style="margin-bottom:12px;font-size:11px;color:var(--muted)">
            Total: <strong style="color:#fff"><?= (int)$currentTotal ?></strong> rows
            <?php if ($currentPages > 1): ?> - Page <?= (int)$page ?> of <?= (int)$currentPages ?><?php endif; ?>
            <?php if (!empty($currentKeyColumns)): ?> - Key: <span><?= e(implode(', ', $currentKeyColumns)) ?></span><?php endif; ?>
          </div>

          <div class="card">
            <div class="tbl-wrap">
              <table>
                <thead>
                  <tr>
                    <?php foreach ($currentBrowse['fields'] as $f): ?><th><?= e($f) ?></th><?php endforeach; ?>
                    <th>Actions</th>
                  </tr>
                </thead>
                <tbody>
                  <?php if (empty($currentBrowse['rows'])): ?>
                    <tr><td colspan="<?= count($currentBrowse['fields']) + 1 ?>">No results.</td></tr>
                  <?php else: ?>
                    <?php foreach ($currentBrowse['rows'] as $row): ?>
                      <?php
                      $keys = array();
                      foreach ($currentKeyColumns as $kc) {
                          $keys[$kc] = ($row[$kc] === null ? '__NULL__' : $row[$kc]);
                      }
                      ?>
                      <tr>
                        <?php foreach ($currentBrowse['fields'] as $f): ?>
                          <td><?= $row[$f] === null ? '<span class="null-val">NULL</span>' : e($row[$f]) ?></td>
                        <?php endforeach; ?>
                        <td>
                          <div class="row">
                            <?php if (!empty($keys)): ?>
                              <a href="<?= e(build_url($self, $_GET, array('view'=>'edit_row','keys_json'=>json_encode($keys)))) ?>" class="btn-link btn-warning btn-sm">Edit</a>

                              <form method="POST" style="display:inline" onsubmit="return confirm('Delete row ini?');">
                                <input type="hidden" name="action" value="delete_row_submit">
                                <input type="hidden" name="table" value="<?= e($table) ?>">
                                <input type="hidden" name="row_keys" value='<?= e(json_encode($keys)) ?>'>
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                              </form>
                            <?php else: ?>
                              <span class="null-val">No key</span>
                            <?php endif; ?>
                          </div>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  <?php endif; ?>
                </tbody>
              </table>
            </div>
          </div>

          <?php if ($currentPages > 1): ?>
            <div class="pagination">
              <?php if ($page > 1): ?><a class="pg-btn" href="<?= e(build_url($self, $_GET, array('page'=>$page-1))) ?>">&lt;</a><?php endif; ?>
              <?php
              $start = max(1, $page - 2);
              $end = min($currentPages, $page + 2);
              for ($p = $start; $p <= $end; $p++):
              ?>
                <a class="pg-btn <?= ($p == $page ? 'active' : '') ?>" href="<?= e(build_url($self, $_GET, array('page'=>$p))) ?>"><?= (int)$p ?></a>
              <?php endfor; ?>
              <?php if ($page < $currentPages): ?><a class="pg-btn" href="<?= e(build_url($self, $_GET, array('page'=>$page+1))) ?>">&gt;</a><?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>

      <?php endif; ?>
    </div>
  </div>
</div>

<script>
(function(){
  var dbSearch = document.getElementById('db-search');
  var tableSearch = document.getElementById('table-search');

  if (dbSearch) {
    dbSearch.addEventListener('input', function() {
      var q = this.value.toLowerCase();
      var items = document.querySelectorAll('#db-list .sidebar-item');
      for (var i = 0; i < items.length; i++) {
        var name = items[i].getAttribute('data-name') || '';
        items[i].style.display = (name.indexOf(q) !== -1 ? '' : 'none');
      }
    });
  }

  if (tableSearch) {
    tableSearch.addEventListener('input', function() {
      var q = this.value.toLowerCase();
      var items = document.querySelectorAll('#table-list .sidebar-item');
      for (var i = 0; i < items.length; i++) {
        var name = items[i].getAttribute('data-name') || '';
        items[i].style.display = (name.indexOf(q) !== -1 ? '' : 'none');
      }
    });
  }
})();

function buildRowPayloadForInsert() {
  var els = document.querySelectorAll('.insert-col');
  var payload = {};
  for (var i = 0; i < els.length; i++) {
    var col = els[i].getAttribute('data-col');
    var nullCb = document.querySelector('.insert-null[data-col="' + String(col).replace(/"/g, '\\"') + '"]');
    if (nullCb && nullCb.checked) payload[col] = '__NULL__';
    else payload[col] = els[i].value;
  }
  document.getElementById('insert_row_payload').value = JSON.stringify(payload);
  return true;
}

function buildRowPayloadForEdit() {
  var els = document.querySelectorAll('.edit-col');
  var payload = {};
  for (var i = 0; i < els.length; i++) {
    var col = els[i].getAttribute('data-col');
    var nullCb = document.querySelector('.edit-null[data-col="' + String(col).replace(/"/g, '\\"') + '"]');
    if (nullCb && nullCb.checked) payload[col] = '__NULL__';
    else payload[col] = els[i].value;
  }
  document.getElementById('edit_row_payload').value = JSON.stringify(payload);
  return true;
}

function addCreateColumn() {
  var box = document.getElementById('column-builder');
  var wrap = document.createElement('div');
  wrap.className = 'card';
  wrap.style.marginBottom = '12px';
  wrap.innerHTML =
    '<div class="card-body">' +
      '<div class="form-group"><label class="form-label">Column Name</label><input type="text" class="form-control c-name"></div>' +
      '<div class="form-group"><label class="form-label">Type</label><input type="text" class="form-control c-type" value="VARCHAR(255)"></div>' +
      '<div class="form-group"><label class="form-label">Default</label><input type="text" class="form-control c-default"></div>' +
      '<div class="row">' +
        '<label><input type="checkbox" class="c-null" checked> Nullable</label>' +
        '<label><input type="checkbox" class="c-primary"> Primary</label>' +
        '<label><input type="checkbox" class="c-ai"> Auto Increment</label>' +
        '<label><input type="checkbox" class="c-default-set"> Use Default</label>' +
        '<button type="button" class="btn btn-danger btn-sm" onclick="this.closest(\'.card\').remove()">Remove</button>' +
      '</div>' +
    '</div>';
  box.appendChild(wrap);
}

function submitCreateTable() {
  var rows = document.querySelectorAll('#column-builder .card');
  var arr = [];
  for (var i = 0; i < rows.length; i++) {
    var r = rows[i];
    var name = (r.querySelector('.c-name').value || '').trim();
    var type = (r.querySelector('.c-type').value || '').trim();
    var def = r.querySelector('.c-default').value || '';
    var nullable = r.querySelector('.c-null').checked;
    var primary = r.querySelector('.c-primary').checked;
    var ai = r.querySelector('.c-ai').checked;
    var defset = r.querySelector('.c-default-set').checked;

    if (!name || !type) continue;

    arr.push({
      name: name,
      type: type,
      nullable: nullable,
      primary: primary,
      auto_increment: ai,
      default_set: defset ? 1 : 0,
      "default": def
    });
  }

  document.getElementById('columns_json').value = JSON.stringify(arr);
  document.getElementById('create-table-form').submit();
}

if (document.getElementById('column-builder')) {
  addCreateColumn();
  addCreateColumn();
}
</script>

<?php endif; ?>
</body>
</html>
<?php if ($mysqli) $mysqli->close(); ?>
