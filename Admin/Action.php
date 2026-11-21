<?php

namespace TypechoPlugin\CommentAudit\Admin;

use Typecho\Db;
use TypechoPlugin\CommentAudit\Ai\DeepSeekClient;
use TypechoPlugin\CommentAudit\Engine;
use TypechoPlugin\CommentAudit\Logger;
use TypechoPlugin\CommentAudit\Plugin;
use TypechoPlugin\CommentAudit\Repository;
use Utils\Helper;
use Widget\ActionInterface;
use Widget\Base;
use Widget\Notice;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 插件后台动作。
 */
class Action extends Base implements ActionInterface
{
    /**
     * 入口。
     */
    public function action()
    {
        $this->user->pass('administrator');
        $this->security->protect();

        $do = (string) $this->request->get('do', '');

        if (in_array($do, ['save-advanced', 'clear-cache', 'set-trust', 'blacklist', 'history-scan', 'history-moderate'], true)
            && !$this->request->isPost()) {
            $this->response->setStatus(405);
            return;
        }

        switch ($do) {
            case 'save-advanced':
                $this->saveAdvanced();
                break;
            case 'test':
                $this->test();
                break;
            case 'test-connection':
                $this->testConnection();
                break;
            case 'audit-info':
                $this->auditInfo();
                break;
            case 'history-scan':
                $this->scanHistory();
                break;
            case 'history-moderate':
                $this->moderateHistory();
                break;
            case 'blacklist':
                $this->blacklist();
                break;
            case 'clear-cache':
                $this->clearCache();
                break;
            case 'set-trust':
                $this->setTrust();
                break;
            default:
                $this->back('overview');
        }
    }

    private function scanHistory(): void
    {
        $scope = $this->request->get('scope', 'approved');
        $withAi = $this->request->get('mode') === 'ai';
        $range = ['options' => ['min_range' => 0]];
        $after = filter_var($this->request->get('after', '0'), FILTER_VALIDATE_INT, $range);
        $upper = $this->request->get('through');
        $through = $upper === null ? null : filter_var($upper, FILTER_VALIDATE_INT, $range);

        if (!in_array($scope, HistoryScan::SCOPES, true) || $after === false || $through === false
            || ($through === null ? $after !== 0 : $after > $through)) {
            $this->response->throwJson(['success' => false, 'message' => _t('扫描范围无效，请重新开始。')]);
            return;
        }

        $settings = Plugin::settings();
        if ($withAi && trim((string) $settings['deepseekApiKey']) === '') {
            $this->response->throwJson(['success' => false, 'message' => _t('请先配置 DeepSeek 密钥，或选择仅用本地规则。')]);
            return;
        }

        try {
            $batch = HistoryScan::batch($scope, $withAi, $after, $through, $settings);
        } catch (\Throwable $e) {
            error_log('[CommentAudit] Historical scan failed: ' . get_class($e));
            $this->response->throwJson(['success' => false, 'message' => _t('扫描失败。可以继续重试当前批次。')]);
            return;
        }

        $this->response->throwJson(['success' => true] + $batch);
    }

    private function moderateHistory(): void
    {
        $raw = $this->request->get('changes');
        $changes = is_string($raw) ? json_decode($raw, true, 4) : null;
        if (!is_array($changes) || !$changes || count($changes) > HistoryModeration::BATCH_SIZE) {
            $this->response->throwJson(['success' => false, 'message' => _t('请选择有效的评论后重试。')]);
            return;
        }

        try {
            $results = HistoryModeration::apply($changes);
        } catch (\InvalidArgumentException $e) {
            $this->response->throwJson(['success' => false, 'message' => _t('评论状态或操作无效，请重新扫描。')]);
            return;
        } catch (\Throwable $e) {
            error_log('[CommentAudit] Historical moderation failed: ' . get_class($e));
            $this->response->throwJson(['success' => false, 'message' => _t('无法确认本批处理结果，请重试。')]);
            return;
        }
        $this->response->throwJson(['success' => true, 'results' => $results]);
    }

    /**
     * 保存高级设置。
     */
    private function saveAdvanced()
    {
        $settings = Plugin::settings();
        $settings['advancedCommentList'] = $this->request->get('advancedCommentList') === '1' ? 1 : 0;
        $settings['logRetention'] = $this->readDays('logRetention', (int) $settings['logRetention'], 3650);
        $settings['cacheTtl'] = $this->readDays('cacheTtl', (int) $settings['cacheTtl'], intdiv(PHP_INT_MAX - time(), 86400));

        Helper::configPlugin(Plugin::PLUGIN_NAME, $settings);
        Notice::alloc()->set(_t('高级设置已保存'), 'success');
        $this->back('advanced');
    }

    private function readDays(string $key, int $current, int $max): int
    {
        $value = filter_var($this->request->get($key), FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1, 'max_range' => $max],
        ]);
        return $value === false ? $current : $value;
    }

    /**
     * 规则测试，不写数据库。
     */
    private function test()
    {
        $comment = [
            'cid' => (int) $this->request->get('cid', 0),
            'author' => (string) $this->request->get('author', ''),
            'mail' => (string) $this->request->get('mail', ''),
            'url' => (string) $this->request->get('url', ''),
            'ip' => (string) $this->request->get('ip', ''),
            'text' => (string) $this->request->get('text', ''),
        ];

        if (trim($comment['text']) === '') {
            $this->response->throwJson([
                'success' => 0,
                'message' => _t('请填写评论内容'),
            ]);
        }

        $tags = (string) $this->request->get('tags', '');
        $article = [
            'cid' => (int) $this->request->get('cid', 0),
            'title' => (string) $this->request->get('title', ''),
            'excerpt' => (string) $this->request->get('excerpt', ''),
            'category' => (string) $this->request->get('category', ''),
            'tags' => $tags === '' ? [] : array_values(array_filter(array_map('trim', explode(',', $tags)))),
        ];

        try {
            $result = Engine::decide($comment, $article, Plugin::settings());
            $result['reason_label'] = Logger::reasonLabel((string) ($result['reason_code'] ?? ''));
            $this->response->throwJson([
                'success' => 1,
                'result' => $result,
            ]);
        } catch (\Throwable $e) {
            $this->response->throwJson([
                'success' => 0,
                'message' => $e->getMessage(),
            ]);
        }
    }

    /**
     * 测试 DeepSeek 连接。
     */
    private function testConnection()
    {
        $comment = [
            'cid' => 0,
            'author' => '连接测试',
            'mail' => '',
            'url' => '',
            'ip' => '127.0.0.1',
            'text' => '这是一条正常的测试评论，用来检查 DeepSeek 连接是否正常。' . time(),
        ];
        $article = [
            'cid' => 0,
            'title' => '连接测试',
            'excerpt' => '这是一篇用于测试评论审核连接的示例文章。',
            'category' => '',
            'tags' => [],
        ];
        $features = [
            'trust_level' => 'normal',
            'approved_count' => 1,
            'spam_count' => 0,
            'last_spam_at' => 0,
            'link_count' => 0,
            'comment_length' => 30,
            'ip_recent_count' => 0,
            'suspect_word' => null,
            'flags' => [],
        ];

        try {
            $result = DeepSeekClient::audit($comment, $article, $features, Plugin::settings(), true);
            $this->response->throwJson([
                'success' => empty($result['error']),
                'result' => $result,
                'message' => !empty($result['error'])
                    ? ($result['diagnostic'] ?? Logger::reasonLabel($result['reason']))
                    : null,
            ]);
        } catch (\Throwable $e) {
            $this->response->throwJson([
                'success' => 0,
                'message' => _t('连接测试失败，请检查 PHP 的错误日志。'),
            ]);
        }
    }

    /**
     * 批量返回评论审核信息，供原生列表增强使用。
     */
    private function auditInfo()
    {
        $raw = (string) $this->request->get('coids', '');
        $coids = array_filter(array_map('intval', explode(',', $raw)));
        $logs = Repository::logsByCoids($coids);

        foreach ($logs as &$log) {
            $log['reason_label'] = Logger::reasonLabel((string) ($log['reason_code'] ?? ''));
        }
        unset($log);

        $this->response->throwJson([
            'success' => 1,
            'logs' => $logs,
        ]);
    }

    /**
     * 从日志/评论快捷拉黑。
     */
    private function blacklist()
    {
        $settings = Plugin::settings();
        $ip = trim((string) $this->request->get('ip', ''));
        $email = trim((string) $this->request->get('email', ''));
        $domain = trim((string) $this->request->get('domain', ''));
        $coid = (int) $this->request->get('coid', 0);
        $handle = (string) $this->request->get('handle', '');
        $added = false;
        $handled = '';

        if ($ip !== '') {
            $settings['ipBlacklist'] = $this->appendLine($settings['ipBlacklist'], $ip);
            $added = true;
        }
        if ($email !== '') {
            $settings['emailBlacklist'] = $this->appendLine($settings['emailBlacklist'], $email);
            $added = true;
        }
        if ($domain !== '') {
            $settings['urlBlacklist'] = $this->appendLine($settings['urlBlacklist'], $domain);
            $added = true;
        }

        // 列表增强里的“拉黑并垃圾/删除”：除了写入黑名单，还要处理当前评论。
        if ($coid > 0 && in_array($handle, ['spam', 'delete'], true)) {
            $db = Db::get();
            $comment = $db->fetchRow(
                $db->select()->from('table.comments')
                    ->where('coid = ?', $coid)
                    ->limit(1)
            );

            if ($comment) {
                $oldStatus = (string) $comment['status'];

                if ($handle === 'spam' && $oldStatus !== 'spam') {
                    $db->query(
                        $db->update('table.comments')
                            ->rows(['status' => 'spam'])
                            ->where('coid = ?', $coid)
                    );

                    if ($oldStatus === 'approved') {
                        $db->query(
                            $db->update('table.contents')
                                ->expression('commentsNum', 'commentsNum - 1')
                                ->where('cid = ?', (int) $comment['cid'])
                                ->where('commentsNum > 0')
                        );
                    }

                    Repository::adjustForMark($comment, $oldStatus, 'spam');
                    Logger::markManual($coid, 'spam', $this->user->uid);
                    $handled = 'spam';
                } elseif ($handle === 'delete') {
                    $db->query($db->delete('table.comments')->where('coid = ?', $coid));

                    if ($oldStatus === 'approved') {
                        $db->query(
                            $db->update('table.contents')
                                ->expression('commentsNum', 'commentsNum - 1')
                                ->where('cid = ?', (int) $comment['cid'])
                                ->where('commentsNum > 0')
                        );
                    }

                    Repository::adjustForDelete($comment);
                    Logger::markManual($coid, 'rejected', $this->user->uid);
                    $handled = 'delete';
                }
            }
        }

        if ($added || $handled !== '') {
            if ($added) {
                Helper::configPlugin(Plugin::PLUGIN_NAME, $settings);
            }

            if ($handled === 'spam') {
                Notice::alloc()->set($added ? _t('已加入黑名单，评论已移到垃圾评论') : _t('评论已移到垃圾评论'), 'success');
            } elseif ($handled === 'delete') {
                Notice::alloc()->set($added ? _t('已加入黑名单，评论已删除') : _t('评论已删除'), 'success');
            } else {
                Notice::alloc()->set(_t('已加入黑名单'), 'success');
            }
        } else {
            Notice::alloc()->set(_t('没有可加入黑名单的内容'), 'notice');
        }

        $referer = (string) $this->request->getReferer();
        $refererHost = $referer !== '' ? parse_url($referer, PHP_URL_HOST) : null;
        $siteHost = parse_url((string) Helper::options()->siteUrl, PHP_URL_HOST);

        if ($referer !== '' && $refererHost && $siteHost && strcasecmp($refererHost, $siteHost) === 0) {
            $this->response->redirect($referer);
        }

        $this->back('logs');
    }

    /**
     * 手动设置白名单或恢复自动信誉。
     */
    private function setTrust()
    {
        $hash = trim((string) $this->request->get('identity_hash', ''));
        $mode = (string) $this->request->get('mode', '');
        $allowed = ['whitelist', 'auto'];

        if ($hash === '' || !in_array($mode, $allowed, true)) {
            Notice::alloc()->set(_t('参数不正确'), 'error');
            $this->back('reputation');
        }

        $db = Db::get();
        $db->query(
            $db->update('table.commentaudit_reputation')
                ->rows([
                    'trust_level' => $mode === 'whitelist' ? 'whitelist' : 'new',
                    'updated' => time(),
                ])
                ->where('identity_hash = ?', $hash)
        );

        Notice::alloc()->set($mode === 'whitelist' ? _t('已加入白名单') : _t('已恢复自动信誉'), 'success');
        $this->back('reputation');
    }

    /**
     * 清空 AI 缓存。
     */
    private function clearCache()
    {
        Repository::clearCache();
        Notice::alloc()->set(_t('AI 缓存已清空'), 'success');
        $this->back('advanced');
    }

    /**
     * @param string $raw
     * @param string $line
     * @return string
     */
    private function appendLine($raw, $line)
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $raw);
        $lines[] = $line;
        $lines = array_values(array_unique(array_filter(array_map('trim', $lines))));

        return implode("\n", $lines);
    }

    /**
     * 返回插件面板。
     *
     * @param string $tab
     */
    private function back(string $tab): void
    {
        $url = Helper::options()->adminUrl(
            'extending.php?panel=' . urlencode('CommentAudit/panel.php') . '&tab=' . urlencode($tab),
            true
        );
        $this->response->redirect($url);
    }
}
