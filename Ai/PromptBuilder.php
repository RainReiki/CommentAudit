<?php

namespace TypechoPlugin\CommentAudit\Ai;

if (!defined('__TYPECHO_ROOT_DIR__')) {
    exit;
}

/**
 * DeepSeek 提示词构建。
 */
class PromptBuilder
{
    public const PROMPT_VERSION = '1.1';

    /**
     * @param array $comment
     * @param array $article
     * @param array $features
     * @return array
     */
    public static function build(array $comment, array $article, array $features)
    {
        $system = <<<'PROMPT'
你是博客评论审核器。请结合评论内容、文章上下文和统计特征，判断评论是否适合公开。

只输出一个 JSON 对象，不要输出 Markdown、解释文字或代码块。

JSON 字段：
{
  "decision": "pass|review|block",
  "category": "normal|ad|fraud|porn|illegal|abuse_mild|abuse_moderate|abuse_severe|threat|hate|politics|irrelevant|self-promo",
  "severity": "low|medium|high",
  "threat_type": "none|joke|genuine|ambiguous",
  "on_topic": true,
  "faithful": true,
  "confidence": 0.0,
  "reason": "简短中文原因"
}

上下文说明：
- article.title 是文章标题；
- article.content 是文章原文，最多 3000 字；
- article.excerpt 是文章摘要；
- comment.text 是待审核评论。

判断要求：
1. pass：正常讨论、正常提问、正常批评，和文章主题相关。
2. review：疑似广告、疑似引战、疑似仇恨、疑似威胁、内容与文章无关、无法确定。
3. block：明确广告、诈骗、色情、赌博、违法、严重辱骂、真实威胁、明显仇恨言论。
4. abuse_mild：轻度负面评价，例如“写得一般”“不太喜欢”。
5. abuse_moderate：中度辱骂，例如“垃圾文章”“浪费时间”。
6. abuse_severe：严重人身攻击，例如“傻逼”“去死”。
7. threat：威胁。threat_type 必须是 genuine（真实威胁）、joke（明显玩笑）、ambiguous（无法判断）。
8. hate：针对群体、地域、性别、民族、宗教、性取向等的仇恨或歧视。
9. on_topic：评论是否围绕当前文章讨论的事件/主题。
10. faithful：评论是否基于文章事实，没有编造或传播明显谣言。
11. confidence：0 到 1 之间的小数，表示你对 decision 的把握。参考区间：0.90–1.00 证据非常明确；0.75–0.89 倾向明确但有少量歧义；0.50–0.74 不确定；低于 0.50 基本无法判断。不要轻易给出 0.90 以上。
12. 不要因为评论出现某个敏感词就直接判定违规，要结合上下文。
13. 不要输出任何个人信息。
PROMPT;

        $excerpt = (string) ($article['excerpt'] ?? '');
        $articleText = (string) ($article['content'] ?? '');
        $commentText = (string) ($comment['text'] ?? '');

        if (function_exists('mb_substr')) {
            $excerpt = mb_substr($excerpt, 0, 200, 'UTF-8');
            $articleText = mb_substr($articleText, 0, 3000, 'UTF-8');
            $commentText = mb_substr($commentText, 0, 2000, 'UTF-8');
        } else {
            $excerpt = substr($excerpt, 0, 200);
            $articleText = substr($articleText, 0, 3000);
            $commentText = substr($commentText, 0, 2000);
        }

        $payload = [
            'comment' => [
                'author' => (string) ($comment['author'] ?? ''),
                'text' => $commentText,
            ],
            'article' => [
                'title' => (string) ($article['title'] ?? ''),
                'content' => $articleText,
                'excerpt' => $excerpt,
                'category' => (string) ($article['category'] ?? ''),
                'tags' => array_values(array_map('strval', (array) ($article['tags'] ?? []))),
            ],
            'features' => $features,
        ];

        $user = "请审核以下评论，只返回 JSON：\n" . json_encode(
            $payload,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
        );

        return [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user', 'content' => $user],
        ];
    }

    /**
     * 缓存 key。
     *
     * @param string $model
     * @param array $comment
     * @param array $article
     * @param array $features
     * @return string
     */
    public static function cacheKey($model, array $comment, array $article, array $features)
    {
        return hash('sha256', json_encode([
            'v' => self::PROMPT_VERSION,
            'model' => (string) $model,
            'text' => (string) ($comment['text'] ?? ''),
            'author' => (string) ($comment['author'] ?? ''),
            'title' => (string) ($article['title'] ?? ''),
            'content' => (string) ($article['content'] ?? ''),
            'excerpt' => (string) ($article['excerpt'] ?? ''),
            'features' => $features,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
    }
}
