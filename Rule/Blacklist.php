<?php

namespace TypechoPlugin\CommentAudit\Rule;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * IP / 邮箱 / 网址黑名单匹配。
 */
class Blacklist
{
    /**
     * 每行一项。
     *
     * @param string $raw
     * @return array
     */
    public static function lines($raw)
    {
        return WordFilter::lines($raw);
    }

    /**
     * IP 精确匹配或 CIDR。
     *
     * @param string $ip
     * @param string $raw
     * @return bool
     */
    public static function matchIp($ip, $raw)
    {
        $ip = trim((string) $ip);
        if ($ip === '') {
            return false;
        }

        $ipLong = ip2long($ip);
        if ($ipLong === false) {
            return false;
        }

        foreach (self::lines($raw) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            if (strpos($line, '/') !== false) {
                [$subnet, $bits] = explode('/', $line, 2);
                $subnetLong = ip2long($subnet);
                $bits = (int) $bits;
                if ($subnetLong === false || $bits < 0 || $bits > 32) {
                    continue;
                }
                $mask = $bits === 0 ? 0 : (-1 << (32 - $bits));
                if (($ipLong & $mask) === ($subnetLong & $mask)) {
                    return true;
                }
            } elseif ($line === $ip) {
                return true;
            }
        }

        return false;
    }

    /**
     * 邮箱精确匹配或域名后缀。
     *
     * @param string $mail
     * @param string $raw
     * @return bool
     */
    public static function matchEmail($mail, $raw)
    {
        $mail = strtolower(trim((string) $mail));
        if ($mail === '' || strpos($mail, '@') === false) {
            return false;
        }

        $domain = substr($mail, strrpos($mail, '@') + 1);

        foreach (self::lines($raw) as $line) {
            $line = strtolower(trim($line));
            if ($line === '') {
                continue;
            }

            if (strpos($line, '*@') === 0) {
                $line = substr($line, 2);
            } elseif (strpos($line, '@') === 0) {
                $line = substr($line, 1);
            }

            if (strpos($line, '@') !== false) {
                if ($line === $mail) {
                    return true;
                }
                continue;
            }

            if ($domain === $line || substr($domain, -strlen('.' . $line)) === '.' . $line) {
                return true;
            }
        }

        return false;
    }

    /**
     * 评论正文和评论者网址中的网址匹配。
     *
     * @param string $text
     * @param string $authorUrl
     * @param string $raw
     * @return bool
     */
    public static function matchUrl($text, $authorUrl, $raw)
    {
        $urls = self::extractUrls((string) $text);
        $authorUrl = trim((string) $authorUrl);
        if ($authorUrl !== '') {
            $urls[] = $authorUrl;
        }

        if (empty($urls)) {
            return false;
        }

        foreach (self::lines($raw) as $rule) {
            $rule = trim($rule);
            if ($rule === '') {
                continue;
            }

            foreach ($urls as $url) {
                if (self::matchOneUrl($url, $rule)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * 提取文本中的 URL/域名候选。
     *
     * @param string $text
     * @return array
     */
    public static function extractUrls($text)
    {
        $text = (string) $text;
        $urls = [];

        if (preg_match_all('~https?://[^\s<>"\']+~iu', $text, $matches)) {
            foreach ($matches[0] as $match) {
                $urls[] = rtrim($match, '.,;:!?)]}');
            }
        }

        if (preg_match_all(
            '~(?<![\w@])(?:www\.)?(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<>"\']*)?~iu',
            $text,
            $matches
        )) {
            foreach ($matches[0] as $match) {
                $urls[] = rtrim($match, '.,;:!?)]}');
            }
        }

        return array_values(array_unique(array_filter($urls)));
    }

    /**
     * 单条 URL 与单条黑名单规则匹配。
     *
     * @param string $url
     * @param string $rule
     * @return bool
     */
    private static function matchOneUrl($url, $rule)
    {
        $url = trim((string) $url);
        $rule = trim((string) $rule);

        if ($url === '' || $rule === '') {
            return false;
        }

        if (strpos($url, '://') === false) {
            $url = 'http://' . $url;
        }

        $parts = parse_url($url);
        if (!$parts || empty($parts['host'])) {
            return false;
        }

        $host = self::normalizeHost($parts['host']);
        $path = isset($parts['path']) ? (string) $parts['path'] : '/';

        if (strpos($rule, '://') !== false) {
            $ruleParts = parse_url($rule);
            if (!$ruleParts || empty($ruleParts['host'])) {
                return false;
            }

            $ruleHost = self::normalizeHost($ruleParts['host']);
            if (!self::hostMatches($host, $ruleHost)) {
                return false;
            }

            $rulePath = isset($ruleParts['path']) ? (string) $ruleParts['path'] : '/';
            return $rulePath === '/' || strpos($path, $rulePath) === 0;
        }

        if (strpos($rule, '*.') === 0) {
            $ruleHost = self::normalizeHost(substr($rule, 2));
            return $host !== $ruleHost && substr($host, -strlen('.' . $ruleHost)) === '.' . $ruleHost;
        }

        if (strpos($rule, '/') !== false) {
            [$ruleHost, $rulePath] = explode('/', $rule, 2);
            $ruleHost = self::normalizeHost($ruleHost);
            $rulePath = '/' . ltrim($rulePath, '/');
            return self::hostMatches($host, $ruleHost) && strpos($path, $rulePath) === 0;
        }

        $ruleHost = self::normalizeHost($rule);
        return self::hostMatches($host, $ruleHost);
    }

    /**
     * @param string $host
     * @param string $ruleHost
     * @return bool
     */
    private static function hostMatches($host, $ruleHost)
    {
        return $host === $ruleHost || substr($host, -strlen('.' . $ruleHost)) === '.' . $ruleHost;
    }

    /**
     * @param string $host
     * @return string
     */
    private static function normalizeHost($host)
    {
        $host = strtolower(trim((string) $host));
        $host = rtrim($host, '.');

        if (strpos($host, 'www.') === 0) {
            $host = substr($host, 4);
        }

        return $host;
    }
}
