<?php

namespace TypechoPlugin\CommentAudit\Update;

if (!defined('__TYPECHO_ROOT_DIR__')) { exit; }

final class GitHub
{
    public const REPOSITORY = 'RainReiki/CommentAudit';

    public function latest(): array
    {
        $release = json_decode($this->download('https://api.github.com/repos/' . self::REPOSITORY . '/releases/latest', 2097152), true);
        if (!is_array($release) || !empty($release['draft']) || !empty($release['prerelease'])) {
            throw new \RuntimeException('没有可用的正式 Release。');
        }
        $version = self::version((string) ($release['tag_name'] ?? ''));
        $asset = null;
        foreach (($release['assets'] ?? []) as $item) {
            if (($item['name'] ?? '') === 'commentaudit-update.json') { $asset = $item; break; }
        }
        if (!$asset || empty($asset['browser_download_url'])) {
            throw new \RuntimeException('该 Release 尚未上传 commentaudit-update.json，无法增量升级。');
        }
        $url = $asset['browser_download_url'];
        if (strpos($url, 'https://github.com/' . self::REPOSITORY . '/releases/download/') !== 0) {
            throw new \RuntimeException('Release 清单地址无效。');
        }
        $manifest = json_decode($this->download($url, 2097152), true);
        Manifest::validate($manifest, $version);
        return ['version' => $version, 'tag' => $release['tag_name'], 'manifest' => $manifest,
            'notes' => (string) ($release['body'] ?? ''),
            'url' => 'https://github.com/' . self::REPOSITORY . '/releases/tag/' . rawurlencode($release['tag_name'])];
    }

    public static function version(string $tag): string
    {
        if (!preg_match('/^v?(\d+\.\d+\.\d+)$/D', $tag, $match)) {
            throw new \RuntimeException('Release 标签须使用 v主版本.次版本.修订号。');
        }
        return $match[1];
    }

    public function file(array $manifest, string $path): string
    {
        $entry = $manifest['files'][$path];
        $url = 'https://raw.githubusercontent.com/' . self::REPOSITORY . '/' . $manifest['commit'] . '/'
            . implode('/', array_map('rawurlencode', explode('/', $path)));
        $body = $this->download($url, $entry['size']);
        if (strlen($body) !== $entry['size'] || !hash_equals($entry['sha256'], hash('sha256', $body))) {
            throw new \RuntimeException('文件校验失败：' . $path);
        }
        return $body;
    }

    public function download(string $url, int $limit): string
    {
        if (!function_exists('curl_init')) { throw new \RuntimeException('在线升级需要 PHP cURL 扩展。'); }
        $body = '';
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 60, CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2, CURLOPT_USERAGENT => 'CommentAudit-Updater/1.1',
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.github+json'],
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, $limit) {
                if (strlen($body) + strlen($chunk) > $limit) { return 0; }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        // Follow redirects ourselves so every destination is checked and credentials are never forwarded.
        for ($redirect = 0; $redirect < 6; ++$redirect) {
            $parts = parse_url($url);
            if (($parts['scheme'] ?? '') !== 'https' || isset($parts['user']) || isset($parts['pass'])
                || (isset($parts['port']) && $parts['port'] !== 443)
                || !in_array($parts['host'] ?? '', ['api.github.com', 'github.com', 'raw.githubusercontent.com',
                    'release-assets.githubusercontent.com', 'objects.githubusercontent.com'], true)) {
                curl_close($curl);
                throw new \RuntimeException('升级下载地址无效。');
            }
            $body = '';
            curl_setopt($curl, CURLOPT_URL, $url);
            $ok = curl_exec($curl);
            $code = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
            $next = curl_getinfo($curl, CURLINFO_REDIRECT_URL);
            if ($ok && in_array($code, [301, 302, 303, 307, 308], true) && $next) { $url = $next; continue; }
            curl_close($curl);
            if (!$ok || $code !== 200) {
                throw new \RuntimeException($code === 403 || $code === 429
                    ? 'GitHub 请求受到限制，请稍后重试。' : 'GitHub 下载失败（HTTP ' . $code . '）。');
            }
            return $body;
        }
        curl_close($curl);
        throw new \RuntimeException('升级下载重定向次数过多。');
    }
}
