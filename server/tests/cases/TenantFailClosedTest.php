<?php

declare(strict_types=1);

use plugin\cccms\support\ApiException;
use plugin\cccms\support\TenantContext;

return static function (): void {
    suite('租户身份缺失时失败关闭（P2-2）');

    /** 只记录条件的最小查询替身：不需要数据库 */
    $recorder = static function (): object {
        return new class {
            /** @var array<int,mixed> */
            public array $wheres = [];

            public function where($field, $op = null, $value = null): static
            {
                $this->wheres[] = [$field, $op, $value];

                return $this;
            }

            public function whereRaw(string $sql): static
            {
                $this->wheres[] = $sql;

                return $this;
            }

            public function has(string $needle): bool
            {
                foreach ($this->wheres as $where) {
                    $text = is_array($where) ? implode(' ', array_map('strval', $where)) : (string)$where;
                    if (str_contains($text, $needle)) {
                        return true;
                    }
                }

                return false;
            }
        };
    };

    test('请求内无身份：隔离表读操作返回空集（1 = 0）', function () use ($recorder): void {
        TenantContext::setRequestDetector(static fn (): bool => true);

        try {
            $query = $recorder();
            TenantContext::applyToModelQuery($query, true);

            ok($query->has('1 = 0'), '请求内缺租户身份时必须拼出永假条件，而不是放行全量');
        } finally {
            TenantContext::setRequestDetector(null);
        }
    });

    test('CLI（不在请求内）无身份：照常跨租户，不加条件', function () use ($recorder): void {
        TenantContext::setRequestDetector(static fn (): bool => false);

        try {
            $query = $recorder();
            TenantContext::applyToModelQuery($query, true);

            same([], $query->wheres, 'CLI / 定时任务需要平台级跨租户处理，不能被拦');
        } finally {
            TenantContext::setRequestDetector(null);
        }
    });

    test('有租户身份：按 tenant_id 收敛（不受失败关闭影响）', function () use ($recorder): void {
        TenantContext::setRequestDetector(static fn (): bool => true);

        try {
            $query = $recorder();
            TenantContext::runAs(7, static fn () => TenantContext::applyToModelQuery($query, true));

            ok($query->has('tenant_id'), '有身份时应拼 tenant_id 条件');
            ok(!$query->has('1 = 0'), '有身份时不应拼永假条件');
        } finally {
            TenantContext::setRequestDetector(null);
        }
    });

    test('请求内无身份的写入被拒绝（503），且显式跨租户不受影响', function (): void {
        TenantContext::setRequestDetector(static fn (): bool => true);

        try {
            $thrown = null;
            $data   = ['name' => 'x'];
            try {
                TenantContext::applyWriteRules($data, true, true);
            } catch (ApiException $e) {
                $thrown = $e;
            }

            ok($thrown instanceof ApiException, '缺身份时写操作必须抛异常');
            same(503, $thrown?->getCode(), '用 503 表示「服务端状态不完整」，而不是 422 业务错误');
            same(['name' => 'x'], $data, '被拒绝时不能留下半写的数据');

            // 登录链路（withoutAllScopes）属于有意跨租户：不拦
            $loginData = ['login_time' => 'now'];
            TenantContext::applyWriteRules($loginData, true, false);
            same(['login_time' => 'now'], $loginData, '显式跨租户的写入不应被拦截');
        } finally {
            TenantContext::setRequestDetector(null);
        }
    });

    test('直查写入（stamp）缺身份同样拒绝', function (): void {
        TenantContext::setRequestDetector(static fn (): bool => true);

        try {
            $thrown = null;
            try {
                TenantContext::stamp('file', ['name' => 'x']);
            } catch (ApiException $e) {
                $thrown = $e;
            }

            ok($thrown instanceof ApiException, '直查 insert 缺租户会写成平台数据，必须拒绝');
        } finally {
            TenantContext::setRequestDetector(null);
        }
    });

    test('mode() 区分租户 / 平台 / 无身份', function (): void {
        same(TenantContext::MODE_ANONYMOUS, TenantContext::mode(), '默认（CLI）应为无身份');
        same(TenantContext::MODE_PLATFORM, TenantContext::runAs(0, static fn (): string => TenantContext::mode()));
        same(TenantContext::MODE_TENANT, TenantContext::runAs(9, static fn (): string => TenantContext::mode()));
    });
};
