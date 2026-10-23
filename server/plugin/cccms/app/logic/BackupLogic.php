<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\ApiException;
use plugin\cccms\support\DbBackup;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\PageInput;
use plugin\cccms\support\SortInput;
use plugin\cccms\support\SysConfig;
use support\Log;
use think\facade\Db;
use Throwable;
use Webman\Http\Response;

/**
 * 数据库备份 / 还原（F3）。
 *
 * ## 为什么备份是同步的
 *
 * 备份是**管理员主动发起的一次性运维动作**，通常几秒到几十秒；同步执行能让「点完就能看到
 * 成功/失败与文件大小」，比丢进队列后盯着一条「进行中」猜要强。大库（几十 GB）应该改用
 * 定时任务（`DbBackupTask` + `sys_crontab`），它有独立进程与超时预算，不占在线请求。
 *
 * ## 还原的护栏（按重要程度）
 *
 * 1. **必须输入数据库名确认**：还原会覆盖现有数据，点错按钮的代价太大；
 * 2. **先自动备份（`pre_restore`）**：MySQL 的 DDL 隐式提交，还原失败无法整体回滚，
 *    唯一可靠的退路就是「还原前那一刻」的快照；
 * 3. **文件锁互斥**：两个还原同时跑会互相 DROP/CREATE，结果不可预测；
 * 4. **校验 sha256**：文件被截断 / 手工替换时立刻拒绝，而不是还原到一半报语法错误。
 *
 * ## 不做数据权限
 *
 * 备份文件是整库快照，包含所有租户与用户的数据；「按部门还原」没有意义。
 * 访问控制完全交给 `cccms:backup:*` 节点（建议只授给平台超管）。
 */
final class BackupLogic
{
    public const KIND_MANUAL      = 'manual';
    public const KIND_CRON        = 'cron';
    public const KIND_PRE_RESTORE = 'pre_restore';

    public const STATUS_RUNNING = 0;
    public const STATUS_DONE    = 1;
    public const STATUS_FAILED  = 2;

    private const TABLE = 'db_backup';

    /** 还原互斥锁（放在备份目录里，跟归档一起被同一个运维边界管） */
    private const LOCK_FILE = '.restore.lock';

    private const SORTABLE = [
        'id'          => 'id',
        'size'        => 'size',
        'create_time' => 'create_time',
    ];

    /**
     * 备份列表。
     *
     * @param  array<string,mixed> $params
     * @return array{total:int,list:array<int,array<string,mixed>>}
     */
    public static function paginate(array $params): array
    {
        $query = Db::name(self::TABLE);

        $kind = trim((string)($params['kind'] ?? ''));
        if ($kind !== '') {
            $query->where('kind', $kind);
        }

        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE);
        $list = $query->page($page, $limit)->select()->toArray();

        foreach ($list as &$row) {
            $row['id']          = (int)$row['id'];
            $row['size']        = (int)$row['size'];
            $row['status']      = (int)$row['status'];
            $row['table_count'] = (int)$row['table_count'];
            $row['row_count']   = (int)$row['row_count'];
            $row['duration']    = (int)$row['duration'];
        }
        unset($row);

        return ['total' => $total, 'list' => $list];
    }

    /**
     * 立即备份（同步）：先落一条「进行中」记录，再跑导出。
     *
     * 先落库再干活：进程中途被 kill 时，列表里会留下一条「进行中」的记录，
     * 运维能看见「有一次备份没跑完」，而不是什么都没有。
     *
     * @return array{id:int,file_name:string,size:int,engine:string,table_count:int,row_count:int,duration:int}
     */
    public static function create(string $kind, int $userId): array
    {
        $now = date('Y-m-d H:i:s');
        $id  = (int)Db::name(self::TABLE)->insertGetId([
            'file_name'   => '',
            'file_path'   => '',
            'size'        => 0,
            'checksum'    => '',
            'engine'      => '',
            'table_count' => 0,
            'row_count'   => 0,
            'kind'        => $kind,
            'status'      => self::STATUS_RUNNING,
            'duration'    => 0,
            'error'       => '',
            'user_id'     => $userId,
            'create_time' => $now,
            'update_time' => $now,
        ]);

        return self::run($id);
    }

    /**
     * 执行指定记录对应的备份（供手动 / 定时 / 还原前调用）。
     *
     * @return array{id:int,file_name:string,size:int,engine:string,table_count:int,row_count:int,duration:int}
     */
    public static function run(int $id): array
    {
        $row = Db::name(self::TABLE)->where('id', $id)->find();
        if (!$row) {
            throw new ApiException(I18n::t('backup.not_found'), 404);
        }
        if ((int)$row['status'] !== self::STATUS_RUNNING) {
            throw new ApiException(I18n::t('backup.already_finished'), 422);
        }

        try {
            $meta = DbBackup::create((string)$row['kind']);

            Db::name(self::TABLE)->where('id', $id)->update([
                'file_name'   => $meta['file_name'],
                'file_path'   => $meta['file_path'],
                'size'        => $meta['size'],
                'checksum'    => $meta['checksum'],
                'engine'      => $meta['engine'],
                'table_count' => $meta['table_count'],
                'row_count'   => $meta['row_count'],
                'duration'    => $meta['duration'],
                'status'      => self::STATUS_DONE,
                'error'       => '',
                'update_time' => date('Y-m-d H:i:s'),
            ]);
        } catch (Throwable $e) {
            Db::name(self::TABLE)->where('id', $id)->update([
                'status'      => self::STATUS_FAILED,
                'error'       => mb_substr($e->getMessage(), 0, 500),
                'update_time' => date('Y-m-d H:i:s'),
            ]);
            Log::error('backup #' . $id . ' failed: ' . $e->getMessage());

            throw new ApiException(I18n::t('backup.failed', ['reason' => $e->getMessage()]), 500);
        }

        return [
            'id'          => $id,
            'file_name'   => $meta['file_name'],
            'size'        => $meta['size'],
            'engine'      => $meta['engine'],
            'table_count' => $meta['table_count'],
            'row_count'   => $meta['row_count'],
            'duration'    => $meta['duration'],
        ];
    }

    /**
     * 删除备份（记录 + 文件）。进行中的不允许删 —— 文件还在写，删了会留下半截归档。
     *
     * @param  array<int,int> $ids
     * @return int 实际删除条数
     */
    public static function delete(array $ids): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $id): bool => $id > 0)));
        if ($ids === []) {
            throw new ApiException(I18n::t('backup.select_none'), 422);
        }

        $rows    = Db::name(self::TABLE)->whereIn('id', $ids)->select()->toArray();
        $deleted = 0;
        foreach ($rows as $row) {
            if ((int)$row['status'] === self::STATUS_RUNNING) {
                continue;
            }
            if ((string)$row['file_path'] !== '') {
                DbBackup::delete(DbBackup::path((string)$row['file_path']));
            }
            Db::name(self::TABLE)->where('id', (int)$row['id'])->delete();
            $deleted++;
        }

        if ($deleted === 0) {
            throw new ApiException(I18n::t('backup.delete_running'), 422);
        }

        return $deleted;
    }

    /** 下载归档（校验状态 + 校验文件存在 + 校验 sha256） */
    public static function download(int $id): Response
    {
        $row  = self::downloadable($id);
        $full = DbBackup::path((string)$row['file_path']);

        $response = new Response();
        $response->withFile($full);
        $response->withHeaders(['Content-Disposition' => self::disposition((string)$row['file_name'])]);

        return $response;
    }

    /**
     * 归档里包含哪些表（还原弹窗的选择框）。
     *
     * 顺带把当前库名带回去：还原确认串要手输库名，让人对着界面抄，
     * 比「请自己去看 .env」现实得多。
     *
     * @return array{database:string,tables:list<string>}
     */
    public static function tables(int $id): array
    {
        $row = Db::name(self::TABLE)->where('id', $id)->find();
        if (!$row) {
            throw new ApiException(I18n::t('backup.not_found'), 404);
        }
        if ((int)$row['status'] !== self::STATUS_DONE) {
            throw new ApiException(I18n::t('backup.not_ready'), 409);
        }

        return [
            'database' => DbBackup::connection()['database'],
            'tables'   => DbBackup::tablesIn(self::existingFile($row)),
        ];
    }

    /**
     * 从备份还原。
     *
     * @param  list<string> $tables  为空表示全量还原
     * @return array{tables:int,statements:int,pre_backup_id:int}
     */
    public static function restore(int $id, array $tables, string $confirm, int $userId): array
    {
        $database = DbBackup::connection()['database'];
        if ($database === '') {
            throw new ApiException(I18n::t('backup.no_database'), 500);
        }

        // 护栏 ①：无论前端怎么提示，后端都要自己确认一次 —— 这个接口一旦误调就是整库覆盖
        if ($confirm !== $database) {
            throw new ApiException(I18n::t('backup.confirm_mismatch', ['database' => $database]), 422);
        }

        $row  = self::downloadable($id);
        $full = self::existingFile($row);

        // 只接受归档里真实存在的表：防止把拼错的表名当成「新建空表」静默执行
        $tables = array_values(array_unique(array_filter(array_map('strval', $tables))));
        if ($tables !== []) {
            $missing = array_diff($tables, DbBackup::tablesIn($full));
            if ($missing !== []) {
                throw new ApiException(
                    I18n::t('backup.tables_missing', ['tables' => implode(', ', $missing)]),
                    422
                );
            }
        }

        // 护栏 ③：同时只允许一个还原
        $lock = self::acquireRestoreLock();
        if ($lock === null) {
            throw new ApiException(I18n::t('backup.restore_locked'), 409);
        }

        try {
            // 护栏 ②：先备份当前库。它失败就**不要**开始还原 ——
            // 没有退路的覆盖，比不还原更危险。
            $pre = self::create(self::KIND_PRE_RESTORE, $userId);

            $result = DbBackup::restore($full, $tables === [] ? null : $tables);

            return [
                'tables'         => $result['tables'],
                'statements'     => $result['statements'],
                'pre_backup_id'  => (int)$pre['id'],
            ];
        } finally {
            self::releaseRestoreLock($lock);
        }
    }

    /**
     * 定时备份（供 `DbBackupTask` 调用）：备份一次 + 按保留策略清理。
     */
    public static function scheduled(): string
    {
        $result = self::create(self::KIND_CRON, 0);
        $pruned = self::prune();

        $parts = [sprintf(
            '备份完成：%s（%s，%d 张表，%.1f MB，%dms）',
            $result['file_name'],
            $result['engine'],
            $result['table_count'],
            $result['size'] / 1048576,
            $result['duration']
        )];

        if ($pruned['count'] > 0 || $pruned['days'] > 0) {
            $parts[] = "清理过期备份 {$pruned['count']} 份（保留份数）、{$pruned['days']} 份（保留天数）";
        }

        return implode('；', $parts);
    }

    /**
     * 保留策略：定时备份按份数留；所有已完成备份按天数留。
     *
     * 手工备份**不受份数限制**（要留多久由人决定），但仍受天数约束，避免目录无限膨胀。
     *
     * @return array{count:int,days:int} 两类策略各删了多少条
     */
    public static function prune(): array
    {
        $keepCount = max(1, SysConfig::getInt('backup.keep_count', 10));
        $keepDays  = max(0, SysConfig::getInt('backup.keep_days', 30));

        $count = 0;
        $keepIds = Db::name(self::TABLE)
            ->where('kind', self::KIND_CRON)
            ->where('status', self::STATUS_DONE)
            ->order('id', 'desc')
            ->limit($keepCount)
            ->column('id');

        $stale = Db::name(self::TABLE)
            ->where('kind', self::KIND_CRON)
            ->where('status', self::STATUS_DONE)
            ->whereNotIn('id', $keepIds ?: [0])
            ->select()->toArray();
        foreach ($stale as $row) {
            DbBackup::delete(DbBackup::path((string)$row['file_path']));
            Db::name(self::TABLE)->where('id', (int)$row['id'])->delete();
            $count++;
        }

        $days = 0;
        if ($keepDays > 0) {
            $expired = Db::name(self::TABLE)
                ->where('status', self::STATUS_DONE)
                ->where('create_time', '<', date('Y-m-d H:i:s', time() - $keepDays * 86400))
                ->select()->toArray();
            foreach ($expired as $row) {
                DbBackup::delete(DbBackup::path((string)$row['file_path']));
                Db::name(self::TABLE)->where('id', (int)$row['id'])->delete();
                $days++;
            }
        }

        return ['count' => $count, 'days' => $days];
    }

    // -----------------------------------------------------------------

    /**
     * 取一条可下载的备份（完成 + 文件在 + 校验和一致）。
     *
     * @return array<string,mixed>
     */
    private static function downloadable(int $id): array
    {
        $row = Db::name(self::TABLE)->where('id', $id)->find();
        if (!$row) {
            throw new ApiException(I18n::t('backup.not_found'), 404);
        }
        if ((int)$row['status'] !== self::STATUS_DONE) {
            throw new ApiException(I18n::t('backup.not_ready'), 409);
        }

        $full = self::existingFile($row);

        // 护栏 ④：文件被截断 / 替换时立刻拒绝。校验失败**不改状态** ——
        // 记录本身是对的，只是文件坏了，留着让人知道「这份备份不可用」。
        if ((string)$row['checksum'] !== '' && hash_file('sha256', $full) !== (string)$row['checksum']) {
            throw new ApiException(I18n::t('backup.checksum_failed'), 409);
        }

        return $row;
    }

    /** @param array<string,mixed> $row */
    private static function existingFile(array $row): string
    {
        $full = DbBackup::path((string)$row['file_path']);
        if (!is_file($full)) {
            throw new ApiException(I18n::t('backup.file_missing'), 410);
        }

        return $full;
    }

    /**
     * 抢还原锁（非阻塞文件锁，跨进程生效，不依赖 Redis）。
     *
     * @return resource|null null = 已有还原在进行
     */
    private static function acquireRestoreLock()
    {
        $handle = @fopen(DbBackup::dir() . DIRECTORY_SEPARATOR . self::LOCK_FILE, 'c');
        if ($handle === false) {
            throw new ApiException(I18n::t('backup.lock_failed'), 500);
        }

        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    /** @param resource $handle */
    private static function releaseRestoreLock($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }

    /**
     * 拼 Content-Disposition：ASCII 兜底名 + RFC 5987 原名（与导出下载同一套口径）。
     */
    private static function disposition(string $name): string
    {
        $ascii = preg_replace('/[^A-Za-z0-9._-]/u', '_', $name) ?: 'backup';

        return sprintf("attachment; filename=\"%s\"; filename*=UTF-8''%s", $ascii, rawurlencode($name));
    }
}
