<?php

declare(strict_types=1);

namespace plugin\cccms\support;

use plugin\cccms\app\model\OperationLog;
use plugin\cccms\support\storage\StorageManager;
use support\Log;

/**
 * 历史日志归档：把 sys_log 的冷数据序列化为 JSONL(gzip) 上传对象存储，成功后再从主库删除。
 *
 * 为什么放在 support：命令（手动 / CI）与定时任务（LogArchiveTask）要共用同一套逻辑，
 * 写两遍必然漂移。
 *
 * 核心安全约束（改动时务必保持）：
 *   1. **先上传成功、再删除**：上传抛异常立即中止，绝不出现「删了没存」；
 *   2. 按批「上传 → 按 id 删除」，天然可重入：删除成功后再跑不会重复选中；
 *      即便「上传成功但删除失败」，下次只是重复归档同一批（有冗余），不会丢数据；
 *   3. 归档前对 params / result 二次脱敏（历史记录可能早于脱敏规则的补充）；
 *   4. 归档会打断审计链的**头部**：每批删除后调 `LogChain::reanchor()` 把校验起点
 *      前移到幸存的第一行，并把本批的段边界哈希写进 `<文件>.chain.json` 供离线核验。
 */
final class LogArchiver
{
    /** 破坏性操作下限：归档会删主库数据，天数过小等于误删，硬性兜底 */
    public const MIN_DAYS = 7;

    /** 单批上限，防止 --batch 传超大值把内存打满 */
    private const MAX_BATCH = 50000;

    /**
     * 执行一次归档。
     *
     * @param  array{days?:int,batch?:int,path?:string,dry_run?:bool} $options
     * @return array<string,mixed> 见方法末尾的返回结构（`dry_run` 时不落盘、不删除）
     */
    public static function archive(array $options = []): array
    {
        $cfg   = (array)config('plugin.cccms.log.archive', []);
        $days  = (int)($options['days'] ?? $cfg['days'] ?? 30);
        $batch = (int)($options['batch'] ?? $cfg['batch'] ?? 1000);
        $pathFilter = trim((string)($options['path'] ?? ''));
        $dry        = (bool)($options['dry_run'] ?? false);

        // 破坏性操作：天数强制不小于下限，batch 收敛到 [1, MAX_BATCH]
        $days  = max($days, self::MIN_DAYS);
        $batch = min(max($batch, 1), self::MAX_BATCH);

        $cutoff = date('Y-m-d H:i:s', strtotime("-{$days} days"));
        $prefix = trim((string)($cfg['path'] ?? 'log-archive'), '/');

        $driverName = (string)($cfg['driver'] ?? '');
        $driverName = $driverName !== '' ? $driverName : StorageManager::current();

        // 逃生口：归档是系统级动作，必须扫描全量日志，绝不能受当前用户的数据权限约束。
        // 显式 withoutGlobalScope() 并从代码可见（见 docs/08 §七）。
        // path 精确匹配可只归档某一类记录，如 --path=/auth/login 只归档登录日志；空 = 全部。
        $build = static function () use ($cutoff, $pathFilter) {
            $query = OperationLog::withoutGlobalScope()->where('create_time', '<', $cutoff);
            if ($pathFilter !== '') {
                $query->where('path', $pathFilter);
            }

            return $query;
        };

        $scanned = (int)$build()->count();

        if ($dry) {
            return [
                'scanned'   => $scanned,
                'archived'  => 0,
                'files'     => 0,
                'remaining' => $scanned,
                'dry_run'   => true,
                'driver'    => $driverName,
                'cutoff'    => $cutoff,
                'path'      => $pathFilter,
            ];
        }

        $driver      = StorageManager::driver($driverName);
        $archived    = 0;
        $files       = 0;
        $seq         = 0;
        $chainBroken = false;

        while (true) {
            // 每轮都取「最旧的 batch 条」：上一轮已删除，天然向前推进，不需要 offset
            $rows = $build()->order('id', 'asc')->limit($batch)->select();
            if ($rows->isEmpty()) {
                break;
            }

            $ids   = [];
            $lines = [];
            $raw   = [];
            foreach ($rows as $row) {
                $data = $row->getData();
                // 模型把 create_time 读成 DateTime 对象，直接 json_encode 会变成 {}（字段丢失），
                // 必须显式格式化回字符串，否则归档文件里的时间字段不可读。
                if (isset($data['create_time']) && !is_string($data['create_time'])) {
                    $ct = $data['create_time'];
                    $data['create_time'] = $ct instanceof \DateTimeInterface
                        ? $ct->format('Y-m-d H:i:s')
                        : (string)$ct;
                }

                // 链校验必须在**二次脱敏之前**做：链式哈希算的是入库时的原始内容，
                // 脱敏改过 params / result 之后重算必然对不上，那是「脱敏」不是「篡改」。
                $raw[] = $data;

                // 归档前再脱敏一次：历史记录可能早于脱敏规则
                $data['params'] = LogRedactor::redactJson(isset($data['params']) ? (string)$data['params'] : null);
                $data['result'] = LogRedactor::redactJson(isset($data['result']) ? (string)$data['result'] : null);

                $ids[]   = (int)$data['id'];
                $lines[] = (string)json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }

            $seq++;
            $path = sprintf(
                '%s/%s/%s/log-%s-%d.jsonl.gz',
                $prefix,
                date('Y'),
                date('m'),
                date('Ymd-His'),
                $seq
            );

            $content = gzencode(implode("\n", $lines) . "\n", 9);
            if ($content === false) {
                throw new ApiException('gzip 压缩失败', 500);
            }

            // ① 先上传：失败会抛异常并中止，主库记录原封不动
            $driver->put($content, $path);
            $files++;

            // ①' 批次校验信息（P2-14）：与数据文件同名 + `.chain.json`，离线可核验。
            //     归档走的永远是「最旧的一批」，因此这批是链上连续的一段。
            $chainMeta = self::chainMeta($raw);
            $driver->put(
                (string)json_encode($chainMeta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $path . '.chain.json'
            );

            if ((int)$chainMeta['broken_at'] > 0) {
                // 归档前就发现链不自洽：仍然继续归档（数据不能丢），但要留下痕迹
                $chainBroken = true;
                Log::error(sprintf(
                    'log chain broken before archive: id=%d batch=%d-%d',
                    $chainMeta['broken_at'],
                    $chainMeta['from_id'],
                    $chainMeta['to_id']
                ));
            }

            // ② 上传成功后再删：单条 DELETE ... IN 原子；此处失败同样抛出，留待下次重试
            OperationLog::withoutGlobalScope()->whereIn('id', $ids)->delete();
            // 归档删的是链头部：把校验起点前移到幸存的第一行，避免后续校验把
            // 「合法归档」误报成断点（链中间的删除仍会被报出）
            LogChain::reanchor();
            $archived += count($ids);
        }

        return [
            'scanned'      => $scanned,
            'archived'     => $archived,
            'files'        => $files,
            'remaining'    => max(0, $scanned - $archived),
            'dry_run'      => false,
            'driver'       => $driverName,
            'cutoff'       => $cutoff,
            'path'         => $pathFilter,
            // 归档过程中是否发现审计链不自洽（true 时数据已归档，但需要人工核查）
            'chain_broken' => $chainBroken,
        ];
    }

    /**
     * 生成批次校验信息（写入 `<归档文件>.chain.json`）。
     *
     * 三个层次的信息，用途不同：
     *   1. `head_hash` / `tail_hash` / `head_prev_hash`：**段边界**。配合归档文件里
     *      每行自带的 `prev_hash` / `row_hash`，离线即可验证「段内链接连续」以及
     *      「这一段接在链的什么位置」；
     *   2. `unchained`：早期（本能力上线前）写入、没有哈希的行数 —— 它们没法被证明，
     *      也不该被当成篡改；
     *   3. `broken_at`：**归档时的实测结论**。归档器手上有脱敏前的原始内容，
     *      因此它能对每一行重算哈希；非 0 表示那一刻链已经不自洽（数据仍会归档，
     *      但必须人工核查）。这一项是离线端做不到的（脱敏后无法重算），
     *      所以必须由归档侧固化下来。
     *
     * @param  array<int,array<string,mixed>> $rows 脱敏前的原始行（含 id / prev_hash / row_hash）
     * @return array<string,mixed>
     */
    private static function chainMeta(array $rows): array
    {
        $headHash = '';
        $tailHash = '';
        $headPrev = '';
        $brokenAt = 0;
        $unchained = 0;

        foreach ($rows as $index => $row) {
            $stored = (string)($row['row_hash'] ?? '');
            if ($index === 0) {
                $headHash = $stored;
                $headPrev = (string)($row['prev_hash'] ?? '');
            }
            $tailHash = $stored;

            if ($stored === '') {
                $unchained++;
                continue;
            }

            $expected = LogChain::hash(
                (string)($row['prev_hash'] ?? ''),
                LogChain::normalize($row, (int)($row['hash_version'] ?? 0))
            );
            if ($expected !== $stored && $brokenAt === 0) {
                $brokenAt = (int)($row['id'] ?? 0);
            }
        }

        return [
            'from_id'        => (int)($rows[0]['id'] ?? 0),
            'to_id'          => (int)($rows[count($rows) - 1]['id'] ?? 0),
            'count'          => count($rows),
            'head_hash'      => $headHash,
            'tail_hash'      => $tailHash,
            'head_prev_hash' => $headPrev,
            'unchained'      => $unchained,
            'broken_at'      => $brokenAt,
            'archived_at'    => date('Y-m-d H:i:s'),
        ];
    }
}
