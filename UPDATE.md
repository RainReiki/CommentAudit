# 在线升级与发布约定

此功能从 **1.1.0** 开始提供。旧版 1.0.29 没有升级入口，需要先手动覆盖安装一次 1.1.0；数据库内现有设置、日志、信誉和缓存保留。插件文件夹必须名为 `CommentAudit`。

## 使用

1. 在“插件设置 → 非中文评论”设置动作和英文占比；默认关闭，阈值 80%。只有正文没有汉字且 A–Z/a–z 占比达到阈值才触发。HTML 标签不计入，实体解码后统计，所有空白排除；数字、标点、emoji 均计入分母。不按昵称、邮箱、网址判断。有汉字、纯数字、纯表情不会触发。
2. 直接拒绝在调用 AI 前结束，记录 `local_english_ratio`；继续 AI 审核遵循原来的所有规则、缓存、额度和失败转待审策略。历史扫描选择“仅本地”时，需要 AI 的英文评论会被标为待审，不会误报为已通过。
3. 到“管理 → 评论审核 → 在线升级”，检查后可以查看变动文件、下载量、迁移版本与 Release 说明，再点击升级。
4. 若服务器或连接中断，刷新后进入在线升级页，点击“恢复中断的升级”。提交点之前恢复旧版本，提交点之后只清理备份。恢复未完成期间暂停审核和插件数据写入。

需要 PHP 8.0+、cURL、HTTPS CA 证书、插件文件写入权限。数据库账号需具备插件表的 SELECT、INSERT、CREATE、ALTER、DROP、INDEX 权限。MySQL / MariaDB 和 SQLite 均支持；备份空间应足够容纳所有插件表及发生变化的文件。请勿在升级窗口同时使用外部脚本修改插件表。

## GitHub 发布

正式版本来自固定仓库 `RainReiki/CommentAudit` 的 `/releases/latest`。仅接受 `vX.Y.Z` 或 `X.Y.Z` 正式标签；草稿和预发布不参与更新。接口限流或下载失败不会改动站点。

每个 Release 必须上传：

- `CommentAudit-X.Y.Z.zip`：用于首次或手动安装。
- `commentaudit-update.json`：完整文件路径、大小、SHA-256、固定提交 SHA，以及累计迁移清单。

推送 `vX.Y.Z` 标签会运行 `.github/workflows/release.yml`，执行测试、生成附件并发布 Release。仓库需要允许 GitHub Actions 写入发布。必须将本次源码、迁移文件、构建脚本和工作流都提交后再打标签，且标签和 `Plugin::VERSION` 一致。不要先发布一个没有附件的正式 Release。

手动构建：

```sh
python tools/build_release.py --version 1.1.0 --commit <40位真实提交SHA> --out dist
```

构建会生成安装包、本地 `update-manifest.json` 和 Release 清单。在线升级仅下载清单及本地哈希不同的文件，文件从清单固定提交的 `raw.githubusercontent.com` 读取，不下载全量源码 ZIP。相同文件不会重写；删除文件仅限上个本地清单登记且未被修改的文件。用户额外文件保留；遇到本地修改、同名冲突、符号链接或路径越界会停止升级。

清单通过 GitHub HTTPS 获取，SHA-256 用于完整性校验；发布权限及 GitHub 账号是更新信任来源。不要修改已经发布的历史迁移。

## 数据库迁移

当前 1.1.0 无表结构变动，`migrations/1.1.0.json` 的操作列表为空。以后每次发生数据库变化，新增对应版本文件；保留所有历史文件。跨版本升级会依次执行 `当前版本 < 迁移版本 ≤ 目标版本` 的全部文件，按数字版本排序（例如 1.9.0 在 1.10.0 之前）。清单会拒绝缺失或被修改的历史迁移。

示例 `migrations/1.2.0.json`：

```json
{
  "version": "1.2.0",
  "operations": [
    {"type": "add_column", "table": "commentaudit_logs", "name": "language_score", "dataType": "INTEGER", "notNull": true, "default": 0},
    {"type": "create_index", "table": "commentaudit_logs", "name": "language_score", "columns": ["language_score"]}
  ]
}
```

迁移使用声明式 JSON，不执行任意 PHP 或任意 SQL；支持 `add_column`、`create_table`、`drop_table`、`create_index`。表名不带 Typecho 前缀，必须以 `commentaudit_` 开头；字段类型支持 INTEGER、BIGINT、TEXT、VARCHAR(n)、DECIMAL(p,s)，默认值为整数、普通文本或 null。新增复杂数据迁移时需要扩展迁移执行器和测试，不能直接放入任意 SQL。新增表结构也需同步 `Plugin::installTables()`，确保新安装与升级后的结构一致。

MySQL DDL 会隐式提交，所以这里不把 BEGIN/ROLLBACK 当成表结构回滚保证。执行迁移前，会为插件所有表建立持久副本并保存完整结构。失败时删除本次新增表，恢复原表结构、索引、数据和序列。SQLite 同样使用持久副本。存在外键或触发器的插件表会拒绝自动数据库迁移，避免越过可恢复范围；Typecho 核心表不会被修改。

## 恢复边界

升级对插件请求使用共享/排他文件锁。文件先下载校验、保存原件，再按迁移顺序变更数据库并原子替换文件，最后写入唯一提交点。每一步的恢复信息在变更前持久化；普通异常自动回滚，进程被强制终止后由后台恢复。回滚本身失败会保留备份和维护状态，可以再次恢复。

这是一组可恢复的文件/数据库操作，不是操作系统和数据库共同提供的分布式事务。断电后可能需要点击恢复，完成恢复前不接收插件写入。独立进程直接操作数据库、丢失备份目录、文件系统/数据库永久不可写不在自动恢复能力之内。

默认工作目录位于系统临时目录 `commentaudit-<插件路径摘要>`，目录权限 0700，不位于网站目录。需要长期保障中断恢复时，在 `config.inc.php` 定义 `COMMENTAUDIT_UPDATE_DIR` 为**网站目录外的持久私有目录**，确保 PHP 用户可写，禁止服务器在升级或恢复完成前清理该目录。数据库快照表以 `caub_` 开头，成功或回滚后自动删除。

## 验证

```sh
php tests/language.php
php -d extension=pdo_sqlite tests/updater.php
```

升级测试使用隔离目录和临时 SQLite 数据库。可指定环境变量 `COMMENTAUDIT_TEST_MYSQL_DSN`、`COMMENTAUDIT_TEST_MYSQL_USER`、`COMMENTAUDIT_TEST_MYSQL_PASSWORD` 在专用空测试库重复运行，不要指向生产数据库。
