<?php

namespace TypechoPlugin\CommentAudit\Admin;

use Typecho\Common;
use Typecho\Widget\Helper\Form;
use Typecho\Widget\Helper\Form\Element;
use Typecho\Widget\Helper\Form\Element\Checkbox;
use Typecho\Widget\Helper\Form\Element\Hidden;
use Typecho\Widget\Helper\Form\Element\Number;
use Typecho\Widget\Helper\Form\Element\Password;
use Typecho\Widget\Helper\Form\Element\Radio;
use Typecho\Widget\Helper\Form\Element\Text;
use Typecho\Widget\Helper\Form\Element\Textarea;
use Typecho\Widget\Helper\Layout;
use TypechoPlugin\CommentAudit\Plugin;
use TypechoPlugin\CommentAudit\Repository;
use Utils\Helper;
use Widget\Options;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

final class SettingsForm
{
    private const RANGES = [
        'deepseekTimeout' => [1, null],
        'deepseekMaxTokens' => [1, null],
        'tokenQuota' => [0, null],
        'ipRateLimit' => [0, null],
        'ipRateWindow' => [60, null],
        'linkLimit' => [0, null],
        'englishRatioThreshold' => [1, 100, 'any'],
        'thresholdNew' => [0, 1, 'any'],
        'thresholdNormal' => [0, 1, 'any'],
        'thresholdTrusted' => [0, 1, 'any'],
    ];

    private const SCOPES = ['text', 'author', 'mail', 'url'];

    public static function render(Form $form, array $settings): void
    {
        $options = Options::alloc();
        $advanced = $options->adminUrl('extending.php?panel=CommentAudit%2Fpanel.php&tab=advanced', true);
        $hasApiKey = trim((string) $settings['deepseekApiKey']) !== '';
        $enabled = ['1' => _t('开启'), '0' => _t('关闭')];
        $thinkingOptions = ['0' => _t('保持默认'), '1' => _t('关闭思考')];
        $groups = [
            'connection' => [_t('DeepSeek 连接'), _t('配置审核服务的地址、模型与访问凭据。'), [
                'deepseekBaseUrl' => [Text::class, _t('服务地址'), _t('默认 https://api.deepseek.com。可填根地址、.../v1 或完整 .../v1/chat/completions，插件会自动尝试补全 /v1。')],
                'deepseekApiKey' => [Password::class, _t('API Key'), !$hasApiKey
                    ? _t('尚未配置。填写并保存后即可测试连接。')
                    : _t('已配置。可替换为新 Key，或清空后保存。')],
                'deepseekModel' => [Text::class, _t('模型'), _t('默认 deepseek-flash。不同服务商模型名不同，保存后可点击「获取模型列表」选择。')],
                'deepseekDisableThinking' => [Radio::class, _t('思考模式'), _t('中转站较慢时可选择「关闭思考」，通常能减少等待；若提示不支持该参数，请改回默认。'), $thinkingOptions],
                'deepseekTimeout' => [Number::class, _t('请求超时（秒）'), _t('默认 8 秒。')],
                'deepseekMaxTokens' => [Number::class, _t('最大 token 数'), _t('默认 1200。中转站较慢时可尝试 800。')],
                'deepseekSslVerify' => [Radio::class, _t('验证 SSL 证书'), _t('生产环境建议开启。'), $enabled],
                'deepseekDebug' => [Radio::class, _t('DeepSeek 调试日志'), _t('仅在排查连接问题时开启。'), $enabled],
            ]],
            'quota' => [_t('API 额度'), _t('限制插件调用 AI 时最多消耗多少令牌。额度用完后新评论转待审，不再请求接口。'), [
                'tokenQuota' => [Number::class, _t('令牌额度'), _t('填 0 表示不限制。按服务商返回的 usage.total_tokens 统计，建议额度 = 每月评论数 × 5,000 × 1.2，并留 20% 缓冲。')],
                'quotaPeriod' => [Radio::class, _t('重置周期'), _t('每日 / 每月自动重置；累计不自动重置。'), [
                    'daily' => _t('每日'),
                    'monthly' => _t('每月'),
                    'total' => _t('累计'),
                ]],
            ]],
            'words' => [_t('词表与匹配范围'), _t('明确违禁词直接拒绝，疑似词交给 AI 判断。'), [
                'blockWords' => [Textarea::class, _t('明确违禁词'), _t('每行一个词。')],
                'suspectWords' => [Textarea::class, _t('疑似敏感词'), _t('每行一个词。')],
                'wordScopes' => [Checkbox::class, _t('检查哪些字段'), _t('可多选。'), array_combine(
                    self::SCOPES, [_t('评论内容'), _t('昵称'), _t('邮箱'), _t('网址')]
                )],
            ]],
            'blacklist' => [_t('黑名单'), _t('命中任一名单的评论会被直接拒绝。'), [
                'ipBlacklist' => [Textarea::class, _t('IP 黑名单'), _t('每行一个 IP 或 CIDR，例如 192.0.2.0/24。')],
                'emailBlacklist' => [Textarea::class, _t('邮箱黑名单'), _t('每行一个邮箱或域名，例如 *@example.com。')],
                'urlBlacklist' => [Textarea::class, _t('网址黑名单'), _t('每行一个域名、通配子域名或 URL 前缀。')],
            ]],
            'language' => [_t('非中文评论'), _t('仅检查评论正文。无中文且英文字母占比达到阈值时触发，不影响含中文评论。'), [
                'englishRuleAction' => [Radio::class, _t('命中后如何处理'), _t('默认关闭。继续 AI 审核会保留原有黑名单、频率限制和 AI 异常转待审策略。'), [
                    'off' => _t('关闭'), 'reject' => _t('直接拒绝'), 'ai' => _t('继续 AI 审核'),
                ]],
                'englishRatioThreshold' => [Number::class, _t('英文占比阈值（%）'), _t('1–100，默认 80。英文字母 A–Z / a–z 数 ÷ 去掉空白后的正文字符数 × 100；数字、标点、表情计入分母，HTML 标签不计入。')],
            ]],
            'precheck' => [_t('频率与链接'), _t('在提交给 AI 之前检查同一 IP 的发评频率和评论中的链接数。'), [
                'ipRateLimit' => [Number::class, _t('同一 IP 评论数上限'), _t('填 0 可关闭频率限制。')],
                'ipRateWindow' => [Number::class, _t('统计窗口（秒）'), _t('最短 60 秒，默认 600 秒。')],
                'linkLimit' => [Number::class, _t('链接数量上限'), _t('超过上限后会标记为疑似，交给 AI 判断。')],
            ]],
            'policy' => [_t('AI 审核阈值'), _t('AI 判定拦截且达到对应置信度时直接拒绝。AI 调用失败时转待审。'), [
                'identityProtection' => [Radio::class, _t('自动检查身份信息'),
                    _t('AI 根据昵称、邮箱前缀与域名、网址域名与是否首页、站点身份和历史记录给出 0–10 分；本地只做占位邮箱、IP 网址等客观检查。最终分取较高值：3–5 分待审，6 分以上直接拒绝；无需录入博友名单。'), $enabled],
                'thresholdNew' => [Number::class, _t('新用户'), _t('默认 0.7。')],
                'thresholdNormal' => [Number::class, _t('普通用户'), _t('默认 0.8。')],
                'thresholdTrusted' => [Number::class, _t('老访客'), _t('默认 0.9。')],
            ]],
        ];

        $form->setAttribute('class', 'commentaudit-scope commentaudit-config');
        $form->addItem(self::html(
            '<link rel="stylesheet" href="' . self::escape(Plugin::assetUrl('admin.css')) . '">'
            . '<script src="' . self::escape(Plugin::assetUrl('settings.js')) . '" defer></script>'
        ));

        $tabs = '';
        $visible = [];
        foreach ($groups as $id => [$title, $description, $fields]) {
            $tabs .= '<button type="button" class="btn" role="tab" id="ca-tab-' . $id
                . '" aria-controls="ca-' . $id . '">' . self::escape($title) . '</button>';
            $section = new Layout('section', ['id' => 'ca-' . $id, 'class' => 'commentaudit-card commentaudit-config-card']);
            $section->addItem(self::html(
                '<h3>' . self::escape($title) . '</h3><p class="commentaudit-desc">' . self::escape($description) . '</p>'
            ));
            $grid = new Layout('div', ['class' => 'commentaudit-config-grid']);
            foreach ($fields as $name => $field) {
                $input = self::input($name, $settings[$name], $field);
                if ($name === 'deepseekModel') {
                    $input->input->setAttribute(
                        'data-commentaudit-models',
                        Helper::security()->getIndex('/action/comment-audit?do=models')
                    );
                }
                $form->addInput($input);
                $form->removeItem($input);
                $grid->addItem($input);
                $visible[$name] = true;
            }
            $section->addItem($grid);
            if ($id === 'quota') {
                $section->addItem(self::html(self::quotaSummary($settings)));
            }
            $form->addItem($section);
        }

        $form->addItem(self::html(
            '<div class="commentaudit-config-footer">'
            . '<div class="commentaudit-config-tabs" role="tablist" aria-label="' . self::escape(_t('设置分组')) . '" hidden>'
            . $tabs . '</div>'
            . '<div class="commentaudit-config-links">'
            . '<a href="' . self::escape($advanced) . '">' . _t('高级设置与维护') . '</a>'
            . '<a href="' . self::escape($options->adminUrl('extending.php?panel=CommentAudit%2Fpanel.php&tab=updates', true)) . '">' . _t('在线升级') . '</a>'
            . '<a href="https://github.com/RainReiki/CommentAudit" target="_blank" rel="noopener noreferrer">'
            . _t('GitHub 发布页') . '</a></div></div>'
        ));

        // Typecho calls getInput() for every stored key, including ones managed elsewhere.
        // Register those keys without emitting fields or copying stored values into the page.
        foreach (array_keys(array_diff_key($settings, $visible)) as $name) {
            $input = new class ($name) extends Hidden {
                public function value($value): Element
                {
                    return $this;
                }
            };
            $form->addInput($input);
            $form->removeItem($input);
        }
    }

    public static function merge(array $settings, array $submitted): array
    {
        $key = $submitted['deepseekApiKey'] ?? null;
        // A field containing masking characters must never replace the stored key.
        if (is_string($key) && strpos($key, '•') === false) {
            $settings['deepseekApiKey'] = trim($key);
        }

        foreach (['blockWords', 'suspectWords', 'ipBlacklist', 'emailBlacklist', 'urlBlacklist'] as $name) {
            if (is_string($submitted[$name] ?? null)) {
                $lines = preg_split('/\r\n|\r|\n/', $submitted[$name]);
                $lines = array_filter(array_map('trim', $lines), static fn ($line) => $line !== '');
                $settings[$name] = implode("\n", array_unique($lines));
            }
        }

        foreach (['deepseekModel', 'deepseekBaseUrl'] as $name) {
            if (is_string($submitted[$name] ?? null) && trim($submitted[$name]) !== '') {
                $settings[$name] = trim($submitted[$name]);
            }
        }

        foreach (['deepseekSslVerify', 'deepseekDisableThinking', 'deepseekDebug', 'identityProtection'] as $name) {
            $value = $submitted[$name] ?? null;
            if (in_array($value, ['0', '1', 0, 1], true)) {
                $settings[$name] = (int) $value;
            }
        }

        if (is_array($submitted['wordScopes'] ?? null)) {
            $scopes = array_filter($submitted['wordScopes'], 'is_string');
            $settings['wordScopes'] = array_values(array_intersect(self::SCOPES, $scopes));
        }

        $languageAction = $submitted['englishRuleAction'] ?? null;
        if (in_array($languageAction, ['off', 'reject', 'ai'], true)) {
            $settings['englishRuleAction'] = $languageAction;
        }

        $quotaPeriod = $submitted['quotaPeriod'] ?? null;
        if (in_array($quotaPeriod, ['daily', 'monthly', 'total'], true)) {
            $settings['quotaPeriod'] = $quotaPeriod;
        }

        foreach (self::RANGES as $name => $range) {
            [$min, $max] = $range;
            $value = $submitted[$name] ?? null;
            if (is_numeric($value) && $value >= $min && ($max === null || $value <= $max)) {
                $settings[$name] = isset($range[2]) ? (float) $value : (int) $value;
            }
        }
        return $settings;
    }

    /**
     * 额度填写建议与当前周期用量。
     *
     * @param array $settings
     * @return string
     */
    private static function quotaSummary(array $settings): string
    {
        $quota = max(0, (int) ($settings['tokenQuota'] ?? 0));
        try {
            $usage = Repository::getTokenUsage((string) ($settings['quotaPeriod'] ?? 'monthly'));
        } catch (\Throwable $e) {
            $usage = ['tokens' => 0];
        }
        $used = max(0, (int) ($usage['tokens'] ?? 0));
        $percent = $quota > 0 ? min(100, (int) round($used / max(1, $quota) * 100)) : 0;
        $remaining = $quota > 0 ? max(0, $quota - $used) : null;
        $reached = $quota > 0 && $used >= $quota;

        $html = '<div class="commentaudit-quota-help">'
            . '<strong>' . self::escape(_t('怎么填？')) . '</strong>'
            . '<p>' . self::escape(_t('一次审核约 3,000–6,000 tokens。建议额度 = 每月评论数 × 5,000 × 1.2，并额外留 20% 缓冲。')) . '</p>'
            . '<p>' . self::escape(_t('例：300 条/月 ≈ 1,800,000；1,000 条/月 ≈ 6,000,000。测试可先填 100,000；0 表示不限制。')) . '</p>'
            . '</div>';

        $summary = '<div class="commentaudit-quota-usage' . ($reached ? ' is-reached' : '') . '">'
            . '<strong>' . self::escape(number_format($used)) . ' tokens</strong>';
        if ($quota > 0) {
            $summary .= '<span>/ ' . self::escape(number_format($quota)) . '（' . $percent . '%）· '
                . self::escape(_t('剩余')) . ' ' . self::escape(number_format((int) $remaining)) . '</span>';
        } else {
            $summary .= '<span>' . self::escape(_t('未设置上限')) . '</span>';
        }
        $summary .= '</div>';

        if ($quota > 0) {
            $summary .= '<div class="commentaudit-quota-meter' . ($reached ? ' is-reached' : '') . '">'
                . '<span style="width:' . (int) $percent . '%"></span></div>';
        }

        if ($reached) {
            $summary .= '<p class="commentaudit-quota-note">' . self::escape(_t('额度已用完，新评论会转待审。可以调高额度。')) . '</p>';
        }

        return $html . $summary;
    }

    private static function input(string $name, $value, array $field): Element
    {
        [$type, $label, $description] = $field;
        if ($type === Password::class) {
            // Typecho reapplies stored values before rendering. A saved credential must stay server-side.
            $input = new class ($name, null, $value, $label, $description) extends Password {
                public function value($value): Element
                {
                    return parent::value(str_repeat('•', Common::strLen(trim((string) $value))));
                }
            };
            $input->input->setAttribute('autocomplete', 'new-password');
        } else {
            $input = new $type($name, $field[3] ?? null, $value, $label, $description);
        }

        if ($input instanceof Checkbox) {
            $input->multiMode();
            $input->setAttribute('class', 'typecho-option commentaudit-choice commentaudit-span');
            $input->setAttribute('role', 'group')->setAttribute('aria-label', self::escape($label));
        } elseif ($input instanceof Radio) {
            $input->setAttribute('class', 'typecho-option commentaudit-choice');
            $input->setAttribute('role', 'group')->setAttribute('aria-label', self::escape($label));
        } elseif ($input instanceof Textarea) {
            $input->input->setAttribute('rows', '5');
        } elseif (isset(self::RANGES[$name])) {
            $range = self::RANGES[$name];
            $input->input->setAttribute('min', (string) $range[0]);
            if ($range[1] !== null) {
                $input->input->setAttribute('max', (string) $range[1]);
            }
            if (isset($range[2])) {
                $input->input->setAttribute('step', $range[2]);
            }
        }
        return $input;
    }

    private static function html(string $html): Layout
    {
        $layout = new Layout();
        return $layout->html($html);
    }

    private static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
