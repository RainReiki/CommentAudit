<?php

namespace TypechoPlugin\CommentAudit\Update;

use TypechoPlugin\CommentAudit\Plugin;

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

final class Updater
{
    private $root;
    private $github;
    private $database;
    private $running = false;

    public function __construct(?string $root = null, $github = null, $database = null)
    {
        $this->root = $root ?? dirname(__DIR__);
        $this->github = $github ?? new GitHub();
        $this->database = $database ?? new Database();
    }

    private function json(string $file): array
    {
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) { throw new \RuntimeException('升级状态文件损坏，请保留工作目录并人工恢复。'); }
        return $data;
    }

    private function persist(array $journal): void
    {
        $this->atomic(Guard::directory() . '/journal.json', json_encode($journal, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    private function atomic(string $path, string $content, ?int $mode = null): void
    {
        $temporary = $path . '.ca-' . bin2hex(random_bytes(6));
        $handle = @fopen($temporary, 'xb');
        if (!$handle) { throw new \RuntimeException('无法写入升级文件：' . basename($path)); }
        try {
            $offset = 0;
            while ($offset < strlen($content)) {
                $written = fwrite($handle, substr($content, $offset));
                if ($written === false || $written === 0) { throw new \RuntimeException('升级文件写入失败。'); }
                $offset += $written;
            }
            if (!fflush($handle)) { throw new \RuntimeException('升级文件刷新失败。'); }
            if (function_exists('fsync') && !fsync($handle)) { throw new \RuntimeException('升级文件同步失败。'); }
        } finally { fclose($handle); }
        if ($mode !== null && !@chmod($temporary, $mode)) { @unlink($temporary); throw new \RuntimeException('无法恢复文件权限。'); }
        if (!@rename($temporary, $path)) { @unlink($temporary); throw new \RuntimeException('无法替换升级文件：' . basename($path)); }
        if (function_exists('opcache_invalidate')) { @opcache_invalidate($path, true); }
        clearstatcache(true, $path);
    }

    private function target(string $path): string
    {
        if ($path !== 'update-manifest.json') { Manifest::path($path); }
        $current = $this->root;
        if (is_link($current)) { throw new \RuntimeException('插件目录不能是符号链接。'); }
        $parts = explode('/', $path);
        foreach ($parts as $i => $part) {
            $current .= '/' . $part;
            if (is_link($current) || ($i < count($parts) - 1 && file_exists($current) && !is_dir($current))) {
                throw new \RuntimeException('升级目标包含链接或路径冲突：' . $path);
            }
        }
        if (is_dir($current)) { throw new \RuntimeException('升级文件与目录冲突：' . $path); }
        return $current;
    }

    public function plan(array $manifest): array
    {
        Manifest::validate($manifest, $manifest['version'] ?? '');
        $oldPath = $this->target('update-manifest.json');
        if (!is_file($oldPath)) { throw new \RuntimeException('缺少本地文件清单，请先手动安装支持在线升级的版本。'); }
        $old = $this->json($oldPath);
        Manifest::validate($old, Plugin::VERSION);
        if (isset($old['migrationBase']) && ($old['migrationBase'] !== ($manifest['migrationBase'] ?? null))) {
            throw new \RuntimeException('数据库迁移基线发生变化，需要手动升级。');
        }
        foreach ($old['migrations'] as $migration) {
            if (!in_array($migration, $manifest['migrations'], true)) {
                throw new \RuntimeException('Release 遗漏历史数据库迁移，请修正发布清单。');
            }
            $file = $migration['file'];
            if ($old['files'][$file] !== $manifest['files'][$file]) {
                throw new \RuntimeException('历史数据库迁移文件不允许修改。');
            }
        }
        $write = []; $delete = [];
        foreach ($manifest['files'] as $path => $entry) {
            $target = $this->target($path);
            $hash = is_file($target) ? hash_file('sha256', $target) : null;
            if ($hash === $entry['sha256']) { continue; }
            if ($hash !== null && (!isset($old['files'][$path]) || $hash !== $old['files'][$path]['sha256'])) {
                throw new \RuntimeException('检测到本地改动或同名文件，请先处理：' . $path);
            }
            $write[] = $path;
        }
        foreach (array_diff_key($old['files'], $manifest['files']) as $path => $entry) {
            $target = $this->target($path);
            if (!is_file($target)) { continue; }
            if (hash_file('sha256', $target) !== $entry['sha256']) {
                throw new \RuntimeException('待删除文件存在本地改动：' . $path);
            }
            $delete[] = $path;
        }
        $migrations = array_values(array_filter($manifest['migrations'], static function ($migration) use ($manifest) {
            return version_compare($migration['version'], Plugin::VERSION, '>')
                && version_compare($migration['version'], $manifest['version'], '<=');
        }));
        return ['write' => $write, 'delete' => $delete, 'migrations' => $migrations,
            'bytes' => array_sum(array_map(static function ($path) use ($manifest) { return $manifest['files'][$path]['size']; }, $write))];
    }

    public function check(): array
    {
        Guard::assertReady();
        $release = $this->github->latest();
        $release['available'] = version_compare($release['version'], Plugin::VERSION, '>');
        if ($release['available']) { $release['plan'] = $this->plan($release['manifest']); }
        unset($release['manifest']);
        return $release;
    }

    public function install(string $expectedVersion): array
    {
        Guard::exclusive();
        try {
            if (Guard::pending()) { throw new \RuntimeException('请先恢复上次中断的升级。'); }
            // Re-read Release metadata on install; never trust a client-supplied URL or file list.
            $release = $this->github->latest();
            $manifest = $release['manifest'];
            if ($release['version'] !== $expectedVersion || !version_compare($expectedVersion, Plugin::VERSION, '>')) {
                throw new \RuntimeException('Release 版本已变化或无需升级，请重新检查更新。');
            }
            $plan = $this->plan($manifest);
            @set_time_limit(0);
            ignore_user_abort(true);
            $id = bin2hex(random_bytes(8));
            $directory = Guard::directory() . '/' . $id;
            if (!mkdir($directory, 0700)) { throw new \RuntimeException('无法创建本次升级目录。'); }
            if (!mkdir($directory . '/runtime', 0700)) { throw new \RuntimeException('无法创建升级恢复运行目录。'); }
            foreach (['Manifest', 'GitHub', 'Database', 'Updater'] as $class) {
                $this->atomic($directory . '/runtime/' . $class . '.php', (string) file_get_contents(__DIR__ . '/' . $class . '.php'), 0600);
            }
            $journal = ['id' => $id, 'root' => $this->root, 'phase' => 'backing_up',
                'from' => Plugin::VERSION, 'to' => $expectedVersion, 'files' => [], 'createdDirs' => [], 'applied' => []];
            $this->persist($journal);
            $this->running = true;
            register_shutdown_function(function () {
                if ($this->running && Guard::pending()) {
                    try { $this->recoverLocked(); } catch (\Throwable $error) { error_log('[CommentAudit] Interrupted update requires recovery.'); }
                }
            });
            try {
                $staged = [];
                foreach ($plan['write'] as $i => $path) {
                    $staged[$path] = $directory . '/stage-' . $i;
                    $content = $this->github->file($manifest, $path);
                    if (strlen($content) !== $manifest['files'][$path]['size']
                        || !hash_equals($manifest['files'][$path]['sha256'], hash('sha256', $content))) {
                        throw new \RuntimeException('文件校验失败：' . $path);
                    }
                    if (substr($path, -4) === '.php') { token_get_all($content, TOKEN_PARSE); }
                    $this->atomic($staged[$path], $content);
                }
                $migrationDocs = [];
                foreach ($plan['migrations'] as $migration) {
                    $file = $migration['file'];
                    $source = $staged[$file] ?? $this->target($file);
                    if (hash_file('sha256', $source) !== $manifest['files'][$file]['sha256']) {
                        throw new \RuntimeException('数据库迁移文件校验失败。');
                    }
                    $document = $this->json($source);
                    if (($document['version'] ?? '') !== $migration['version']) { throw new \RuntimeException('迁移版本不匹配。'); }
                    $migrationDocs[] = $document;
                }
                // Check the staged entrypoint is for this Release before mutating anything.
                $entrypoint = (string) file_get_contents($staged['Plugin.php'] ?? $this->target('Plugin.php'));
                if (!preg_match("/public const VERSION = '" . preg_quote($expectedVersion, '/') . "';/", $entrypoint)) {
                    throw new \RuntimeException('插件版本与 Release 不一致。');
                }
                $paths = array_merge($plan['write'], $plan['delete'], ['update-manifest.json']);
                foreach ($paths as $i => $path) {
                    $target = $this->target($path);
                    $backup = null; $mode = 0644;
                    if (is_file($target)) {
                        $backup = 'original-' . $i;
                        $mode = fileperms($target) & 0777;
                        $this->atomic($directory . '/' . $backup, (string) file_get_contents($target), 0600);
                    }
                    $journal['files'][$path] = ['backup' => $backup, 'mode' => $mode];
                    $this->persist($journal);
                }
                if ($migrationDocs) { $this->database->snapshot($journal, function ($state) { $this->persist($state); }); }
                $journal['phase'] = 'applying';
                $this->persist($journal);
                foreach ($migrationDocs as $document) {
                    $this->database->migrate($document);
                    $journal['applied'][] = $document['version'];
                    $this->persist($journal);
                }
                // Plugin.php is the entrypoint and is replaced last, while the exclusive lock is held.
                usort($paths, static function ($a, $b) { return ($a === 'Plugin.php' ? 1 : 0) <=> ($b === 'Plugin.php' ? 1 : 0); });
                foreach ($paths as $path) {
                    $target = $this->target($path);
                    $parent = dirname($target); $missing = [];
                    while (!is_dir($parent)) { $missing[] = $parent; $parent = dirname($parent); }
                    foreach (array_reverse($missing) as $dir) {
                        $journal['createdDirs'][] = $dir;
                        $this->persist($journal);
                        if (!mkdir($dir, 0755) && !is_dir($dir)) { throw new \RuntimeException('无法创建升级目标目录。'); }
                    }
                    if ($path === 'update-manifest.json') {
                        $this->atomic($target, json_encode($manifest, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR), $journal['files'][$path]['mode']);
                    } elseif (in_array($path, $plan['delete'], true)) {
                        if (is_file($target) && !unlink($target)) { throw new \RuntimeException('无法删除旧文件：' . $path); }
                        if (function_exists('opcache_invalidate')) { @opcache_invalidate($target, true); }
                    } else {
                        $this->atomic($target, (string) file_get_contents($staged[$path]), $journal['files'][$path]['mode']);
                    }
                }
                foreach ($manifest['files'] as $path => $entry) {
                    if (!is_file($this->target($path)) || hash_file('sha256', $this->target($path)) !== $entry['sha256']) {
                        throw new \RuntimeException('安装后文件校验失败：' . $path);
                    }
                }
                $journal['phase'] = 'committed';
                $this->persist($journal); // The single durable commit point.
                $this->running = false;
                $this->finish($journal);
                return ['version' => $expectedVersion, 'changed' => count($plan['write']),
                    'deleted' => count($plan['delete']), 'migrations' => $journal['applied']];
            } catch (\Throwable $error) {
                $this->running = false;
                try { $outcome = $this->recoverLocked(); }
                catch (\Throwable $rollbackError) {
                    error_log('[CommentAudit] Rollback incomplete: ' . $rollbackError->getMessage());
                    throw new \RuntimeException('升级未完成，自动回滚也未完成。请点击“恢复中断的升级”。原始错误：' . $error->getMessage());
                }
                if (($outcome['status'] ?? '') === 'committed') {
                    return ['version' => $expectedVersion, 'changed' => count($plan['write']), 'deleted' => count($plan['delete']), 'migrations' => $journal['applied']];
                }
                throw new \RuntimeException('升级失败，文件和数据库已回滚：' . $error->getMessage());
            }
        } finally { Guard::release(); }
    }

    public function recover(): array
    {
        Guard::exclusive();
        try { return $this->recoverLocked(); }
        finally { Guard::release(); }
    }

    private function recoverLocked(): array
    {
        if (!Guard::pending()) { return ['status' => 'idle']; }
        $journal = $this->json(Guard::directory() . '/journal.json');
        if (($journal['root'] ?? '') !== $this->root || !preg_match('/^[a-f0-9]{16}$/D', (string) ($journal['id'] ?? ''))) {
            throw new \RuntimeException('恢复目录不匹配。');
        }
        if (!in_array($journal['phase'], ['committed', 'rolled_back'], true)) {
            $this->database->restore($journal);
            $directory = Guard::directory() . '/' . $journal['id'];
            foreach (array_reverse($journal['files'], true) as $path => $copy) {
                $target = $this->target($path);
                if ($copy['backup'] !== null) {
                    $backup = $directory . '/' . $copy['backup'];
                    if (!is_file($backup)) { throw new \RuntimeException('缺少文件备份：' . $path); }
                    $this->atomic($target, (string) file_get_contents($backup), $copy['mode']);
                } elseif (is_file($target) && !unlink($target)) { throw new \RuntimeException('无法移除新增文件。'); }
                if (function_exists('opcache_invalidate')) { @opcache_invalidate($target, true); }
            }
            foreach (array_reverse($journal['createdDirs']) as $dir) { if (is_dir($dir)) { @rmdir($dir); } }
            $journal['phase'] = 'rolled_back';
            $this->persist($journal);
        }
        $status = $journal['phase'];
        $this->finish($journal);
        return ['status' => $status, 'version' => $status === 'committed' ? $journal['to'] : $journal['from']];
    }

    private function finish(array $journal): void
    {
        $this->database->cleanup($journal);
        $directory = Guard::directory() . '/' . $journal['id'];
        $summary = ['from' => $journal['from'], 'to' => $journal['to'], 'status' => $journal['phase'],
            'migrations' => $journal['applied'], 'time' => time()];
        $this->atomic(Guard::directory() . '/last.json', json_encode($summary, JSON_THROW_ON_ERROR));
        // Delete the journal before removing recovery material: committed/rolled_back is durable already.
        if (!unlink(Guard::directory() . '/journal.json')) { throw new \RuntimeException('无法清理升级状态。'); }
        foreach (glob($directory . '/runtime/*.php') ?: [] as $file) { @unlink($file); }
        @rmdir($directory . '/runtime');
        foreach (glob($directory . '/*') ?: [] as $file) { if (is_file($file) && !is_link($file)) { @unlink($file); } }
        @rmdir($directory);
    }
}
