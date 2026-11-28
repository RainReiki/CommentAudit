<?php
namespace Typecho { class Db { const WRITE = 2; } }
namespace TypechoPlugin\CommentAudit { class Plugin { const VERSION = '1.1.0'; } }
namespace {
    define('__TYPECHO_ROOT_DIR__', __DIR__);
    $sandbox = sys_get_temp_dir() . '/ca-updater-test-' . bin2hex(random_bytes(6));
    mkdir($sandbox, 0700);
    define('COMMENTAUDIT_UPDATE_DIR', $sandbox . '/state');
    spl_autoload_register(static function ($class) {
        $prefix = 'TypechoPlugin\\CommentAudit\\';
        if (strpos($class, $prefix) === 0) { require dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
    });
    use TypechoPlugin\CommentAudit\Update\Database;
    use TypechoPlugin\CommentAudit\Update\Manifest;
    use TypechoPlugin\CommentAudit\Update\Guard;
    use TypechoPlugin\CommentAudit\Update\Updater;

    class Adapter {
        public $pdo;
        public $prefix;
        public $mysql;
        public function __construct() {
            $dsn = getenv('COMMENTAUDIT_TEST_MYSQL_DSN');
            $this->mysql = (bool) $dsn;
            $this->pdo = new PDO($dsn ?: 'sqlite::memory:', getenv('COMMENTAUDIT_TEST_MYSQL_USER') ?: null,
                getenv('COMMENTAUDIT_TEST_MYSQL_PASSWORD') ?: null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $this->prefix = 'cat_' . bin2hex(random_bytes(4)) . '_';
        }
        public function query($sql, ...$args) { return $this->pdo->query($sql); }
        public function fetchAll($statement) { return $statement->fetchAll(PDO::FETCH_ASSOC); }
        public function getPrefix() { return $this->prefix; }
        public function getAdapterName() { return $this->mysql ? 'Pdo_Mysql' : 'Pdo_SQLite'; }
    }
    class Source {
        public $files;
        public $manifest;
        public $downloads = [];
        public $corrupt = false;
        public function __construct($files, $version = '1.3.0', $migrations = []) {
            $this->files = $files;
            $this->manifest = manifest($files, $version, $migrations);
        }
        public function latest() { return ['version' => $this->manifest['version'], 'manifest' => $this->manifest]; }
        public function file($manifest, $path) {
            $this->downloads[] = $path;
            if ($this->corrupt) { throw new RuntimeException('Simulated checksum failure'); }
            return $this->files[$path];
        }
    }
    class FailingDatabase {
        public $fail = true;
        private $database;
        public function __construct($adapter) { $this->database = new Database($adapter); }
        public function snapshot(array &$journal, callable $persist): void { $this->database->snapshot($journal, $persist); }
        public function cleanup(array $journal): void { $this->database->cleanup($journal); }
        public function migrate(array $document): void {
            $this->database->migrate($document);
            throw new RuntimeException('Simulated failure after DDL');
        }
        public function restore(array $journal): void {
            if ($this->fail) { throw new RuntimeException('Simulated temporary recovery failure'); }
            $this->database->restore($journal);
        }
    }
    function manifest($files, $version, $migrations = []) {
        $entries = [];
        foreach ($files as $path => $content) { $entries[$path] = ['size' => strlen($content), 'sha256' => hash('sha256', $content)]; }
        return ['format' => 1, 'version' => $version, 'commit' => str_repeat('a', 40), 'php' => '8.0',
            'migrationBase' => '1.0.29', 'files' => $entries, 'migrations' => $migrations];
    }
    function recoveryFiles() {
        $files = [];
        foreach (glob(dirname(__DIR__) . '/Update/*.php') as $file) { $files['Update/' . basename($file)] = file_get_contents($file); }
        return $files;
    }
    $checks = 0;
    function check($condition, $message) { global $checks; if (!$condition) { throw new RuntimeException($message); } ++$checks; }
    function fails($callable, $message) {
        try { $callable(); } catch (Throwable $error) { check(strpos($error->getMessage(), $message) !== false, $error->getMessage()); return; }
        throw new RuntimeException('Expected failure: ' . $message);
    }
    function fixture($name) {
        global $sandbox;
        $root = $sandbox . '/' . $name; mkdir($root);
        $old = ['Plugin.php' => "<?php class FixturePlugin { public const VERSION = '1.1.0'; }", 'same.txt' => 'same', 'old.txt' => 'delete'] + recoveryFiles();
        foreach ($old as $file => $content) {
            if (!is_dir(dirname($root . '/' . $file))) { mkdir(dirname($root . '/' . $file), 0700, true); }
            file_put_contents($root . '/' . $file, $content);
        }
        file_put_contents($root . '/user-extra.txt', 'user');
        file_put_contents($root . '/update-manifest.json', json_encode(manifest($old, '1.1.0')));
        touch($root . '/same.txt', 1000000000);
        return $root;
    }
    function resetTables($adapter) {
        $p = $adapter->prefix;
        foreach (['logs', 'reputation', 'ai_cache', 'state'] as $table) {
            $adapter->query("CREATE TABLE {$p}commentaudit_{$table} (id INTEGER PRIMARY KEY, value VARCHAR(100))");
            $adapter->query("INSERT INTO {$p}commentaudit_{$table} VALUES (1,'original')");
        }
        $adapter->query("CREATE TABLE {$p}options (value INTEGER)");
        $adapter->query("INSERT INTO {$p}options VALUES (42)");
    }
    function targetSource() {
        $migrations = []; $files = ['Plugin.php' => "<?php class FixturePlugin { public const VERSION = '1.3.0'; }", 'same.txt' => 'same', 'new/added.txt' => 'new'] + recoveryFiles();
        foreach (['1.2.0', '1.3.0'] as $i => $version) {
            $file = 'migrations/' . $version . '.json';
            $files[$file] = json_encode(['version' => $version, 'operations' => $i === 0
                ? [['type' => 'add_column', 'table' => 'commentaudit_logs', 'name' => 'added', 'dataType' => 'INTEGER', 'default' => 0],
                   ['type' => 'create_table', 'table' => 'commentaudit_new', 'columns' => [['name' => 'id', 'dataType' => 'INTEGER', 'primary' => true]]]]
                : [['type' => 'create_index', 'table' => 'commentaudit_logs', 'name' => 'added', 'columns' => ['added']]]]);
            $migrations[] = ['version' => $version, 'file' => $file];
        }
        return new Source($files, '1.3.0', $migrations);
    }
    $adapters = [];
    try {
        $adapter = new Adapter(); $adapters[] = $adapter; resetTables($adapter);
        $database = new Database($adapter);
        $root = fixture('success'); $source = targetSource();
        $updater = new Updater($root, $source, $database);
        $plan = $updater->plan($source->manifest);
        check(!in_array('same.txt', $plan['write']), 'Unchanged file excluded');
        check($plan['delete'] === ['old.txt'], 'Only managed old file deleted');
        $result = $updater->install('1.3.0');
        check($result['migrations'] === ['1.2.0', '1.3.0'], 'Cross-version migrations ordered');
        check(file_get_contents($root . '/new/added.txt') === 'new', 'New file installed');
        check(!file_exists($root . '/old.txt') && file_exists($root . '/user-extra.txt'), 'Delete scope');
        check(filemtime($root . '/same.txt') === 1000000000 && !in_array('same.txt', $source->downloads), 'Unchanged file never downloaded or touched');
        check(count($adapter->query('SELECT added FROM ' . $adapter->prefix . 'commentaudit_logs')->fetchAll()) === 1, 'DDL applied');
        check(!Guard::pending(), 'Journal cleared on success');

        $adapter = new Adapter(); $adapters[] = $adapter; resetTables($adapter);
        $database = new Database($adapter);
        $root = fixture('rollback'); $source = targetSource();
        $source->files['migrations/1.3.0.json'] = json_encode(['version' => '1.3.0', 'operations' => [['type' => 'unsupported', 'table' => 'commentaudit_logs']]]);
        $source->manifest = manifest($source->files, '1.3.0', $source->manifest['migrations']);
        $updater = new Updater($root, $source, $database);
        fails(static function () use ($updater) { $updater->install('1.3.0'); }, '已回滚');
        check(file_get_contents($root . '/Plugin.php') === "<?php class FixturePlugin { public const VERSION = '1.1.0'; }", 'Entrypoint restored');
        fails(static function () use ($adapter) { $adapter->query('SELECT added FROM ' . $adapter->prefix . 'commentaudit_logs'); }, $adapter->mysql ? 'Unknown column' : 'no such column');
        check(count($database->tables()) === 4, 'New table removed by rollback');
        check($adapter->query('SELECT value FROM ' . $adapter->prefix . 'commentaudit_logs')->fetchColumn() === 'original', 'Original rows restored');
        check((int) $adapter->query('SELECT value FROM ' . $adapter->prefix . 'options')->fetchColumn() === 42, 'Core tables untouched');
        check(!Guard::pending(), 'Rollback journal cleared');

        $root = fixture('conflict'); file_put_contents($root . '/old.txt', 'local edit');
        $updater = new Updater($root, targetSource(), $database);
        fails(static function () use ($updater) { $updater->check(); }, '本地改动');
        check(file_get_contents($root . '/old.txt') === 'local edit', 'Local edit preserved');
        $root = fixture('checksum'); $source = targetSource(); $source->corrupt = true;
        $updater = new Updater($root, $source, $database);
        fails(static function () use ($updater) { $updater->install('1.3.0'); }, '已回滚');
        check(!Guard::pending(), 'Download failure clears journal');

        $root = fixture('interrupted'); $database = new FailingDatabase($adapter);
        $updater = new Updater($root, targetSource(), $database);
        fails(static function () use ($updater) { $updater->install('1.3.0'); }, '自动回滚也未完成');
        check(Guard::pending(), 'Failed rollback preserves durable journal');
        fails(static function () { Guard::assertReady(); }, '上次升级中断');
        $database->fail = false;
        check($updater->recover()['status'] === 'rolled_back', 'Recovery can be retried');
        check(!Guard::pending(), 'Recovery clears maintenance');

        // Reconstruct a durable journal at the point a process was killed after file replacement.
        $database = new Database($adapter);
        $root = fixture('partial-files');
        $id = bin2hex(random_bytes(8));
        $directory = Guard::directory() . '/' . $id;
        mkdir($directory); mkdir($directory . '/runtime');
        foreach (['Manifest', 'GitHub', 'Database', 'Updater'] as $class) {
            copy(dirname(__DIR__) . '/Update/' . $class . '.php', $directory . '/runtime/' . $class . '.php');
        }
        $journal = ['id' => $id, 'root' => $root, 'phase' => 'backing_up', 'from' => '1.1.0', 'to' => '1.3.0',
            'files' => [], 'createdDirs' => [$root . '/new'], 'applied' => []];
        $persist = static function ($state) { file_put_contents(Guard::directory() . '/journal.json', json_encode($state)); };
        $database->snapshot($journal, $persist);
        foreach (['Plugin.php', 'old.txt', 'update-manifest.json'] as $i => $path) {
            copy($root . '/' . $path, $directory . '/original-' . $i);
            $journal['files'][$path] = ['backup' => 'original-' . $i, 'mode' => 0644];
        }
        $journal['files']['new/added.txt'] = ['backup' => null, 'mode' => 0644];
        $journal['phase'] = 'applying'; $persist($journal);
        $database->migrate(['operations' => [
            ['type' => 'add_column', 'table' => 'commentaudit_logs', 'name' => 'interrupted', 'dataType' => 'INTEGER'],
            ['type' => 'drop_table', 'table' => 'commentaudit_reputation']]]);
        file_put_contents($root . '/Plugin.php', 'partial replacement');
        unlink($root . '/old.txt'); mkdir($root . '/new'); file_put_contents($root . '/new/added.txt', 'partial new file');
        file_put_contents($root . '/update-manifest.json', '{}');
        $child = '<?php define("__TYPECHO_ROOT_DIR__", __DIR__); define("COMMENTAUDIT_UPDATE_DIR", '
            . var_export(Guard::directory(), true) . '); require ' . var_export(dirname(__DIR__) . '/Update/Guard.php', true)
            . '; \\TypechoPlugin\\CommentAudit\\Update\\Guard::recoveryRuntime(); '
            . '$reflection = new ReflectionClass(\\TypechoPlugin\\CommentAudit\\Update\\Updater::class); '
            . 'echo $reflection->getFileName();';
        file_put_contents($sandbox . '/runtime-child.php', $child);
        $process = proc_open([PHP_BINARY, '-r', 'eval(base64_decode($argv[1]));', base64_encode(substr($child, 5))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        fclose($pipes[0]); $childOutput = stream_get_contents($pipes[1]); $childError = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Fresh-process recovery bootstrap: ' . $childError . $childOutput);
        check(str_replace('\\', '/', $childOutput) === str_replace('\\', '/', $directory . '/runtime/Updater.php'), 'Fresh process loads backup updater');
        $recovered = (new Updater($root, targetSource(), $database))->recover();
        check($recovered['status'] === 'rolled_back', 'Mid-file-replacement interruption recovered');
        check(strpos(file_get_contents($root . '/Plugin.php'), '1.1.0') !== false, 'Partially replaced entrypoint restored');
        check(file_get_contents($root . '/old.txt') === 'delete', 'Deleted file restored');
        check(!file_exists($root . '/new/added.txt') && !is_dir($root . '/new'), 'New files and directories removed');
        check(json_decode(file_get_contents($root . '/update-manifest.json'), true)['version'] === '1.1.0', 'Local manifest restored');
        check(count($database->tables()) === 4, 'Dropped original table restored');
        fails(static function () use ($adapter) { $adapter->query('SELECT interrupted FROM ' . $adapter->prefix . 'commentaudit_logs'); }, $adapter->mysql ? 'Unknown column' : 'no such column');

        // A committed journal must only clean up, never undo an installed release.
        $id = bin2hex(random_bytes(8)); $directory = Guard::directory() . '/' . $id; mkdir($directory);
        $journal = ['id' => $id, 'root' => $root, 'phase' => 'committed', 'from' => '1.1.0', 'to' => '1.3.0',
            'files' => [], 'createdDirs' => [], 'applied' => ['1.2.0', '1.3.0']];
        $persist($journal); file_put_contents($root . '/Plugin.php', 'committed version');
        check((new Updater($root, targetSource(), $database))->recover()['status'] === 'committed', 'Committed recovery retains target');
        check(file_get_contents($root . '/Plugin.php') === 'committed version', 'Committed files unchanged');

        $root = fixture('syntax'); $source = targetSource();
        $source->files['bad.php'] = '<?php syntax error !';
        $source->manifest = manifest($source->files, '1.3.0', $source->manifest['migrations']);
        $updater = new Updater($root, $source, $database);
        fails(static function () use ($updater) { $updater->install('1.3.0'); }, '已回滚');
        check(!file_exists($root . '/bad.php'), 'Invalid PHP rejected before application');

        $child = '<?php $handle=fopen(' . var_export(Guard::directory() . '/lock', true)
            . ',"c+"); flock($handle,LOCK_SH); echo "ready\\n"; fflush(STDOUT); fgets(STDIN); fclose($handle);';
        file_put_contents($sandbox . '/lock-child.php', $child);
        $process = proc_open([PHP_BINARY, '-r', 'eval(base64_decode($argv[1]));', base64_encode(substr($child, 5))], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        check(trim(fgets($pipes[1])) === 'ready', 'Concurrent reader acquired lock');
        fails(static function () { Guard::exclusive(); }, '仍有评论审核请求');
        fclose($pipes[0]); fclose($pipes[1]); fclose($pipes[2]);
        check(proc_close($process) === 0, 'Concurrent reader released lock');

        $root = fixture('historical-migrations'); $source = targetSource();
        mkdir($root . '/migrations'); file_put_contents($root . '/migrations/1.1.0.json', '{"version":"1.1.0","operations":[]}');
        $old = json_decode(file_get_contents($root . '/update-manifest.json'), true);
        $old['files']['migrations/1.1.0.json'] = ['size' => filesize($root . '/migrations/1.1.0.json'), 'sha256' => hash_file('sha256', $root . '/migrations/1.1.0.json')];
        $old['migrations'][] = ['version' => '1.1.0', 'file' => 'migrations/1.1.0.json'];
        file_put_contents($root . '/update-manifest.json', json_encode($old));
        $updater = new Updater($root, $source, $database);
        fails(static function () use ($updater) { $updater->check(); }, '遗漏历史');

        foreach (['../evil.php', 'assets/../../evil', 'C:/evil', 'a\\evil', 'a//b', 'a/CON.php', 'update-manifest.json'] as $path) {
            fails(static function () use ($path) { Manifest::path($path); }, '非法路径');
        }
        $bad = targetSource()->manifest;
        $bad['migrations'] = array_reverse($bad['migrations']);
        fails(static function () use ($bad) { Manifest::validate($bad, '1.3.0'); }, '按版本递增');
        echo 'Updater (' . ($adapter->mysql ? 'MySQL' : 'SQLite') . "): {$checks} checks passed\n";
    } finally {
        foreach ($adapters as $adapter) {
            $database = new Database($adapter);
            foreach ($database->tables() as $table) { $adapter->query('DROP TABLE ' . $table); }
            $adapter->query('DROP TABLE IF EXISTS ' . $adapter->prefix . 'options');
        }
        // Only recursively delete the freshly generated test directory.
        $items = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($sandbox, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($items as $item) { $item->isDir() && !$item->isLink() ? rmdir($item->getPathname()) : unlink($item->getPathname()); }
        rmdir($sandbox);
    }
}
