<?php
namespace Typecho\Plugin { interface PluginInterface {} }
namespace TypechoPlugin\CommentAudit {
    class Repository {
        public static function reputation($comment) { return ['computed_level' => 'new']; }
        public static function recentIpCount(...$args) { return 0; }
        public static function duplicateCount(...$args) { return 0; }
    }
}
namespace TypechoPlugin\CommentAudit\Ai {
    class DeepSeekClient {
        public static $calls = 0;
        public static $fail = false;
        public static function audit(...$args) {
            ++self::$calls;
            return self::$fail ? ['error' => true, 'reason' => 'ai_missing_key']
                : ['decision' => 'pass', 'category' => 'normal', 'confidence' => 0.95];
        }
    }
}
namespace {
    define('__TYPECHO_ROOT_DIR__', __DIR__);
    define('COMMENTAUDIT_UPDATE_DIR', sys_get_temp_dir() . '/ca-language-' . getmypid());
    function _t($text) { return $text; }
    spl_autoload_register(static function ($class) {
        $prefix = 'TypechoPlugin\\CommentAudit\\';
        if (strpos($class, $prefix) === 0) { require dirname(__DIR__) . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php'; }
    });
    use TypechoPlugin\CommentAudit\Plugin;
    use TypechoPlugin\CommentAudit\Engine;
    use TypechoPlugin\CommentAudit\Rule\LocalPrecheck;
    use TypechoPlugin\CommentAudit\Admin\SettingsForm;
    use TypechoPlugin\CommentAudit\Ai\DeepSeekClient;

    $count = 0;
    function check($condition, $message) {
        global $count;
        if (!$condition) { throw new RuntimeException($message); }
        ++$count;
    }
    $settings = Plugin::defaultSettings();
    $settings['identityProtection'] = 0;
    check(!LocalPrecheck::englishRule('Hello', $settings)['matched'], 'Disabled by default');
    $settings['englishRuleAction'] = 'reject';
    foreach ([['Hello world!', true], ['AAAA1', true], ['AAA11', false], ['中文 Hello world', false],
        ['&#20013;Hello', false], ['<p>Hello&nbsp;world</p>', true], ['123456', false], ['😀😀', false],
        ['', false], ['  \t ', false], ['éééé', false], ["A\u{1F600}", false], ['𠀀Hello', false],
        ["\xFFABC", false]] as [$text, $matched]) {
        check(LocalPrecheck::englishRule($text, $settings)['matched'] === $matched, 'Language case: ' . bin2hex($text));
    }
    $settings['englishRatioThreshold'] = 100;
    check(LocalPrecheck::englishRule('Hello world', $settings)['matched'], '100% alphabetic');
    check(!LocalPrecheck::englishRule('Hello!', $settings)['matched'], 'Punctuation counts');
    $settings['englishRatioThreshold'] = 80;
    $result = Engine::decide(['text' => 'AAAA1'], [], $settings);
    check($result['decision'] === 'rejected' && $result['reason_code'] === 'local_english_ratio', 'Hard reject');
    check(DeepSeekClient::$calls === 0, 'Hard reject never calls AI');
    $settings['englishRuleAction'] = 'ai';
    $result = Engine::decide(['text' => 'Hello world!'], [], $settings);
    check($result['decision'] === 'approved' && in_array('english_ratio', $result['flags']), 'AI continuation');
    check(DeepSeekClient::$calls === 1, 'AI called once');
    DeepSeekClient::$fail = true;
    check(Engine::decide(['text' => 'Hello world!'], [], $settings)['decision'] === 'waiting', 'AI failure remains waiting');
    $settings['blockWords'] = 'Hello';
    check(Engine::decide(['text' => 'Hello world!'], [], $settings)['reason_code'] === 'local_block_word', 'Blacklist precedence');
    $settings['blockWords'] = '';
    check(Engine::preview(['text' => 'Hello world!'], [], $settings, false)['reason_code'] === 'history_english_ai', 'Local scan requires AI');
    $settings['englishRuleAction'] = 'reject';
    check(Engine::preview(['text' => 'Hello world!'], [], $settings, false)['decision'] === 'rejected', 'Historical hard rule');
    $merged = SettingsForm::merge($settings, ['englishRuleAction' => 'invalid', 'englishRatioThreshold' => 0]);
    check($merged['englishRuleAction'] === 'reject' && $merged['englishRatioThreshold'] === 80, 'Invalid settings ignored');
    $merged = SettingsForm::merge($settings, ['englishRuleAction' => 'ai', 'englishRatioThreshold' => '82.5']);
    check($merged['englishRuleAction'] === 'ai' && $merged['englishRatioThreshold'] === 82.5, 'Settings merge');
    check($merged['deepseekApiKey'] === $settings['deepseekApiKey'], 'Unrelated settings preserved');
    echo "Language: {$count} checks passed\n";
}
