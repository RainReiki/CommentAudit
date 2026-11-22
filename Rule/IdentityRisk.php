<?php

namespace TypechoPlugin\CommentAudit\Rule;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * 身份风险评分。
 *
 * 主要的“像不像灌水/冒用”判断交给 AI：AI 结合昵称、邮箱前缀与域名、网址域名、
 * 站点身份和历史记录给出 0-10 分。本地只保留不需要外部知识的客观检查：
 * 纯数字/测试式邮箱、IP 网址、punycode、网址内嵌凭据。
 *
 * 最终分 = max(AI 分, 本地分)。中风险转待审，高风险直接拒绝。
 */
final class IdentityRisk
{
    private const RANK = ['low' => 0, 'medium' => 1, 'high' => 2];
    public const MEDIUM_SCORE = 3;
    public const HIGH_SCORE = 6;

    /** 测试式/占位邮箱前缀，只做前缀模式匹配，不是域名清单。 */
    private const PLACEHOLDER_EMAILS = [
        'test', 'testing', 'ceshi', 'demo', 'admin', 'administrator', 'root',
        'guest', 'example', 'sample', 'fake', 'spam', 'temp', 'tmp', 'abc',
        'aaa', 'yourname', 'your', 'name', 'mail', 'email',
    ];

    /**
     * 本地客观检查。
     *
     * @param array $comment
     * @return array{risk:string,score:int,category:string,sources:array,evidence:array}
     */
    public static function evaluate(array $comment): array
    {
        $mail = strtolower(trim((string) ($comment['mail'] ?? '')));
        $url = trim((string) ($comment['url'] ?? ''));

        $signals = [];
        if (self::placeholderEmail($mail)) {
            self::addSignal($signals, 'placeholder_email', '邮箱前缀为纯数字或测试式占位', 2);
        }

        $host = self::host($url);
        if ($host !== '') {
            if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
                self::addSignal($signals, 'ip_website', '网址使用 IP 地址', 2);
            }
            if (strpos($host, 'xn--') !== false) {
                self::addSignal($signals, 'encoded_website', '网址域名包含异常编码', 2);
            }
            if (self::hasUserInfo($url)) {
                self::addSignal($signals, 'credential_website', '网址包含跳转凭据', 2);
            }
        }

        $score = 0;
        $sources = [];
        $evidence = [];
        foreach ($signals as $signal) {
            $score += (int) $signal['weight'];
            $sources[] = (string) $signal['code'];
            $evidence[] = (string) $signal['label'];
        }

        return [
            'risk' => self::riskFromScore($score),
            'score' => $score,
            'category' => $score >= self::MEDIUM_SCORE ? 'profile_conflict' : 'none',
            'sources' => $sources,
            'evidence' => array_slice($evidence, 0, 4),
        ];
    }

    /**
     * 合并 AI 身份分和本地客观分。
     *
     * @param array|null $identity
     * @param array $local
     * @return array|null
     */
    public static function merge($identity, array $local): ?array
    {
        $hasAi = is_array($identity) && isset($identity['risk']);
        $localScore = max(0, (int) ($local['score'] ?? 0));
        $localRisk = (string) ($local['risk'] ?? self::riskFromScore($localScore));

        if (!$hasAi && self::rank($localRisk) === 0) {
            return null;
        }

        if (!$hasAi) {
            $identity = ['risk' => 'low', 'category' => 'none', 'sources' => [], 'evidence' => []];
        }

        $aiScore = self::aiScore($identity);
        $score = max($aiScore, $localScore);
        $risk = self::riskFromScore($score);
        $aiCategory = (string) ($identity['category'] ?? 'none');
        $localCategory = (string) ($local['category'] ?? 'none');

        if ($aiScore >= $localScore && $aiCategory !== 'none') {
            $category = $aiCategory;
        } elseif ($localCategory !== 'none') {
            $category = $localCategory;
        } else {
            $category = $aiCategory;
        }
        if ($category === 'none' && $risk !== 'low') {
            $category = 'profile_conflict';
        }

        $sources = array_values(array_unique(array_merge(
            (array) ($identity['sources'] ?? []),
            (array) ($local['sources'] ?? [])
        )));

        $aiEvidence = array_values(array_filter((array) ($identity['evidence'] ?? []), 'is_string'));
        $localEvidence = array_values(array_filter((array) ($local['evidence'] ?? []), 'is_string'));
        $evidence = $aiScore >= $localScore
            ? array_merge($aiEvidence, $localEvidence)
            : array_merge($localEvidence, $aiEvidence);
        $evidence = array_slice(array_values(array_unique($evidence)), 0, 4);
        if ($risk !== 'low' && empty($evidence)) {
            $evidence = ['身份信息组合异常'];
        }

        return [
            'risk' => $risk,
            'category' => $category,
            'sources' => $sources,
            'evidence' => $evidence,
            'score' => $score,
            'ai_score' => $aiScore,
            'local_score' => $localScore,
            'local_risk' => $localRisk,
        ];
    }

    /**
     * AI 身份分。优先使用 AI 返回的 0-10 分；没有分时按 risk 估算。
     *
     * @param array $identity
     * @return int
     */
    private static function aiScore(array $identity): int
    {
        if (isset($identity['score']) && is_numeric($identity['score'])) {
            return max(0, min(10, (int) round((float) $identity['score'])));
        }

        return match (self::rank((string) ($identity['risk'] ?? 'low'))) {
            2 => 7,
            1 => 4,
            default => 0,
        };
    }

    /**
     * @param int $score
     * @return string
     */
    private static function riskFromScore(int $score): string
    {
        if ($score >= self::HIGH_SCORE) {
            return 'high';
        }

        return $score >= self::MEDIUM_SCORE ? 'medium' : 'low';
    }

    /**
     * 风险等级排序。
     *
     * @param string $risk
     * @return int
     */
    public static function rank(string $risk): int
    {
        return self::RANK[$risk] ?? 0;
    }

    /**
     * 邮箱是否像占位地址。
     *
     * @param string $mail
     * @return bool
     */
    private static function placeholderEmail(string $mail): bool
    {
        $at = strrpos($mail, '@');
        if ($at === false || $at === 0) {
            return false;
        }

        $local = strtolower(substr($mail, 0, $at));
        if (preg_match('/^\d{1,6}$/', $local)) {
            return true;
        }

        $compact = preg_replace('/[^a-z0-9]/', '', $local) ?? '';
        if ($compact === '') {
            return false;
        }

        foreach (self::PLACEHOLDER_EMAILS as $token) {
            if ($compact === $token) {
                return true;
            }
            if (strpos($compact, $token) === 0
                && preg_match('/^\d{0,6}$/', substr($compact, strlen($token)))) {
                return true;
            }
        }

        return false;
    }

    /**
     * 提取网址域名。
     *
     * @param string $url
     * @return string
     */
    private static function host(string $url): string
    {
        if ($url === '') {
            return '';
        }

        $candidate = strpos($url, '://') === false ? 'http://' . $url : $url;
        $host = parse_url($candidate, PHP_URL_HOST);
        if (!is_string($host) || $host === '') {
            return '';
        }

        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }

    /**
     * @param string $url
     * @return bool
     */
    private static function hasUserInfo(string $url): bool
    {
        $candidate = strpos($url, '://') === false ? 'http://' . $url : $url;
        return (bool) preg_match('#^[a-z][a-z0-9+.-]*://[^/?#]*@#i', $candidate);
    }

    /**
     * @param array $signals
     * @param string $code
     * @param string $label
     * @param int $weight
     * @return void
     */
    private static function addSignal(array &$signals, string $code, string $label, int $weight): void
    {
        if (!isset($signals[$code])) {
            $signals[$code] = ['code' => $code, 'label' => $label, 'weight' => $weight];
        }
    }
}
