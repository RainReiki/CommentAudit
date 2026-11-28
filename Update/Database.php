<?php

namespace TypechoPlugin\CommentAudit\Update;

use Typecho\Db;

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

/** Durable table copies also undo MySQL DDL, which implicitly commits transactions. */
final class Database
{
    private $db;
    private $prefix;
    private $sqlite;

    public function __construct($db = null)
    {
        $this->db = $db ?? Db::get();
        $this->prefix = $this->db->getPrefix();
        $adapter = strtolower((string) $this->db->getAdapterName());
        $this->sqlite = strpos($adapter, 'sqlite') !== false;
        if (!$this->sqlite && strpos($adapter, 'mysql') === false) {
            throw new \RuntimeException('在线升级仅支持 MySQL / MariaDB 和 SQLite。');
        }
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $this->prefix)) {
            throw new \RuntimeException('数据库表前缀不适用于自动升级。');
        }
    }

    private function sql(string $sql)
    {
        return $this->db->query($sql, Db::WRITE);
    }

    private function rows(string $sql): array
    {
        return $this->db->fetchAll($this->sql($sql));
    }

    private function quote(string $name): string
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $name)) { throw new \RuntimeException('数据库标识符无效。'); }
        return '`' . $name . '`';
    }

    public function tables(): array
    {
        $names = [];
        $rows = $this->rows($this->sqlite ? "SELECT name FROM sqlite_master WHERE type='table'" : 'SHOW TABLES');
        foreach ($rows as $row) {
            $name = (string) reset($row);
            if (strpos($name, $this->prefix . 'commentaudit_') === 0) { $names[] = $name; }
        }
        sort($names);
        return $names;
    }

    public function snapshot(array &$journal, callable $persist): void
    {
        $journal['database'] = ['tables' => $this->tables(), 'copies' => []];
        $persist($journal);
        foreach ($journal['database']['tables'] as $i => $table) {
            $q = $this->quote($table);
            if ($this->sqlite) {
                $schema = $this->rows("SELECT sql FROM sqlite_master WHERE tbl_name='{$table}' AND sql IS NOT NULL ORDER BY CASE type WHEN 'table' THEN 0 ELSE 1 END");
                $sqls = array_column($schema, 'sql');
                foreach ($sqls as $sql) {
                    if (preg_match('/\b(TRIGGER|REFERENCES)\b/i', $sql)) {
                        throw new \RuntimeException('插件表存在触发器或外键，无法自动保证数据库回滚。');
                    }
                }
                $sequence = null;
                if ($this->rows("SELECT name FROM sqlite_master WHERE name='sqlite_sequence'")) {
                    $seq = $this->rows("SELECT seq FROM sqlite_sequence WHERE name='{$table}'");
                    $sequence = isset($seq[0]['seq']) ? (int) $seq[0]['seq'] : null;
                }
            } else {
                $schema = $this->rows('SHOW CREATE TABLE ' . $q);
                $sqls = [array_values($schema[0])[1]];
                $triggers = $this->rows('SHOW TRIGGERS');
                foreach ($triggers as $trigger) {
                    if (($trigger['Table'] ?? '') === $table) {
                        throw new \RuntimeException('插件表存在触发器，无法自动保证数据库回滚。');
                    }
                }
                if (preg_match('/\b(FOREIGN KEY|REFERENCES)\b/i', $sqls[0])) {
                    throw new \RuntimeException('插件表存在外键，无法自动保证数据库回滚。');
                }
                $sequence = null;
            }
            $backup = 'caub_' . $journal['id'] . '_' . $i;
            $journal['database']['copies'][$table] = ['backup' => $backup, 'schema' => $sqls, 'sequence' => $sequence];
            $persist($journal); // Record intent before creating even a backup table.
            if ($this->sqlite) {
                $this->sql('CREATE TABLE ' . $this->quote($backup) . ' AS SELECT * FROM ' . $q);
            } else {
                $this->sql('CREATE TABLE ' . $this->quote($backup) . ' LIKE ' . $q);
                $this->sql('INSERT INTO ' . $this->quote($backup) . ' SELECT * FROM ' . $q);
            }
        }
    }

    /** Migration documents contain declarative operations, never arbitrary PHP or raw SQL. */
    public function migrate(array $document): void
    {
        $operations = $document['operations'] ?? null;
        if (!is_array($operations) || count($operations) > 100) { throw new \RuntimeException('迁移文件格式无效。'); }
        foreach ($operations as $op) {
            $table = (string) ($op['table'] ?? '');
            if (!preg_match('/^commentaudit_[a-z0-9_]+$/D', $table)) { throw new \RuntimeException('迁移只允许修改插件自己的表。'); }
            $q = $this->quote($this->prefix . $table);
            switch ($op['type'] ?? '') {
                case 'add_column':
                    $this->sql('ALTER TABLE ' . $q . ' ADD COLUMN ' . $this->column($op));
                    break;
                case 'create_table':
                    if (empty($op['columns']) || !is_array($op['columns'])) { throw new \RuntimeException('建表字段不能为空。'); }
                    $columns = array_map(function ($column) { return $this->column($column); }, $op['columns']);
                    $this->sql('CREATE TABLE ' . $q . ' (' . implode(', ', $columns) . ')'
                        . ($this->sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4'));
                    break;
                case 'drop_table':
                    $this->sql('DROP TABLE ' . $q);
                    break;
                case 'create_index':
                    if (empty($op['columns']) || !is_array($op['columns'])) { throw new \RuntimeException('索引字段不能为空。'); }
                    $columns = array_map(function ($name) { return $this->quote($name); }, $op['columns']);
                    $this->sql('CREATE ' . (!empty($op['unique']) ? 'UNIQUE ' : '') . 'INDEX '
                        . $this->quote($this->prefix . $table . '_' . (string) ($op['name'] ?? ''))
                        . ' ON ' . $q . ' (' . implode(', ', $columns) . ')');
                    break;
                default:
                    throw new \RuntimeException('不支持的数据库迁移操作。');
            }
        }
    }

    private function column(array $column): string
    {
        $type = strtoupper((string) ($column['dataType'] ?? ''));
        if (!preg_match('/^(INTEGER|BIGINT|TEXT|VARCHAR\([1-9][0-9]{0,3}\)|DECIMAL\([1-9][0-9]?,[0-9]{1,2}\))$/D', $type)) {
            throw new \RuntimeException('迁移字段类型无效。');
        }
        $sql = $this->quote((string) ($column['name'] ?? '')) . ' ' . $type;
        if (!empty($column['primary'])) { $sql .= ' PRIMARY KEY'; }
        if (!empty($column['notNull'])) { $sql .= ' NOT NULL'; }
        if (array_key_exists('default', $column)) {
            $value = $column['default'];
            // Hex string literals avoid connection-dependent backslash escaping in MySQL.
            if ($value === null) { $sql .= ' DEFAULT NULL'; }
            elseif (is_int($value)) { $sql .= ' DEFAULT ' . $value; }
            elseif (is_string($value) && preg_match('/^[A-Za-z0-9_ .-]*$/D', $value)) { $sql .= " DEFAULT '" . $value . "'"; }
            else { throw new \RuntimeException('迁移默认值仅支持整数、普通文本或 null。'); }
        }
        return $sql;
    }

    public function restore(array $journal): void
    {
        $snapshot = $journal['database'] ?? null;
        if (!$snapshot) { return; }
        // Backups are complete before phase changes; a partial snapshot never replaces live data.
        if ($journal['phase'] !== 'backing_up') {
            foreach (array_diff($this->tables(), $snapshot['tables']) as $table) {
                $this->sql('DROP TABLE ' . $this->quote($table));
            }
            foreach ($snapshot['copies'] as $table => $copy) {
                $q = $this->quote($table);
                $this->sql('DROP TABLE IF EXISTS ' . $q);
                $this->sql($copy['schema'][0]);
                $this->sql('INSERT INTO ' . $q . ' SELECT * FROM ' . $this->quote($copy['backup']));
                foreach (array_slice($copy['schema'], 1) as $sql) { $this->sql($sql); }
                if ($this->sqlite && $copy['sequence'] !== null) {
                    $this->sql("DELETE FROM sqlite_sequence WHERE name='{$table}'");
                    $this->sql("INSERT INTO sqlite_sequence (name,seq) VALUES ('{$table}'," . $copy['sequence'] . ')');
                }
            }
        }
    }

    public function cleanup(array $journal): void
    {
        foreach (($journal['database']['copies'] ?? []) as $copy) {
            $this->sql('DROP TABLE IF EXISTS ' . $this->quote($copy['backup']));
        }
    }
}
