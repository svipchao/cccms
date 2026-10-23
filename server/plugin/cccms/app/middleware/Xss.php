<?php

declare(strict_types=1);

namespace plugin\cccms\app\middleware;

use Webman\Http\Request;
use Webman\Http\Response;
use Webman\MiddlewareInterface;

/**
 * 全局 XSS 过滤：递归清洗 POST 与 GET 字符串入参。
 *
 * 说明：主防线是前端渲染（Vue 默认转义）+ JSON 输出；此处为纵深防御的一层，
 * 只剥离明显危险片段，不改动普通文本（如 "a < b"）。
 *
 * GET 也必须清洗：列表 keyword、导出条件、日志详情筛选都走 query，
 * 这些值会进入列表回显与 CSV 导出（导出文件用 Excel 打开是二次渲染面）。
 */
class Xss implements MiddlewareInterface
{
    /** 不做清洗的字段（密码、富文本、结构化参数） */
    private const EXCEPT = [
        'password', 'old_password', 'new_password', 'password_confirm',
        'content', 'rich_text', 'html',
        // 结构化 / 机器参数：清洗只会引入歧义，且它们不参与任何回显
        'page', 'limit', 'sort', 'order', 'sort_by', 'direction', 'timezone',
    ];

    private const DANGEROUS_TAGS = 'script|iframe|object|embed|applet|meta|link|style|base|form';

    public function process(Request $request, callable $handler): Response
    {
        $post = $request->post();
        if ($post) {
            $request->setPost($this->clean($post));
        }

        $get = $request->get();
        if ($get) {
            $request->setGet($this->clean($get));
        }

        return $handler($request);
    }

    /**
     * @param array<string,mixed> $data
     * @return array<string,mixed>
     */
    private function clean(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                $result[$key] = $this->clean($value);
            } elseif (is_string($value)) {
                $result[$key] = in_array(strtolower((string)$key), self::EXCEPT, true)
                    ? $value
                    : $this->sanitize($value);
            } else {
                $result[$key] = $value;
            }
        }
        return $result;
    }

    private function sanitize(string $value): string
    {
        if ($value === '') {
            return '';
        }
        $tags = self::DANGEROUS_TAGS;

        // 成对标签整体移除
        $value = (string)preg_replace("#<\s*($tags)[^>]*>.*?<\s*/\s*\\1\s*>#is", '', $value);
        // 自闭合 / 未闭合标签
        $value = (string)preg_replace("#<\s*/?\s*($tags)[^>]*>#is", '', $value);
        // on* 事件属性
        $value = (string)preg_replace("#\son[a-z]+\s*=\s*(\"[^\"]*\"|'[^']*'|[^\s>]+)#is", '', $value);
        // javascript: / vbscript: 协议
        $value = (string)preg_replace('#(javascript|vbscript)\s*:#is', '', $value);

        return $value;
    }
}
