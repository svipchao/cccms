<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\support\PageInput;
use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Notice;
use plugin\cccms\app\model\NoticeRead;
use plugin\cccms\app\model\NoticeTarget;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\app\model\UserRole;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\SortInput;
use plugin\cccms\support\Tree;
use plugin\cccms\support\UserContext;
use think\db\BaseQuery;
use think\facade\Db;

/**
 * 通知公告。
 *
 * 两套入口：
 *   - **管理侧**（需权限节点 `cccms:notice:*`）：全量增删改查 + 已读回执报表；
 *   - **阅读侧**（登录即可）：只看「已发布 + 未过期 + 投放给自己」的，并维护已读状态。
 *
 * 定向投放（`scope` + `sys_notice_target`）：
 *   - `scope = 0` 全部用户；`1` 指定部门（含下级）；`2` 指定角色（含后代）；`3` 指定用户；
 *   - 阅读侧只看「投放给自己」的公告，未命中则既看不到、也标不了已读。
 *
 * 「已读人数」用 `sys_notice.read_count` 冗余计数（列表不必实时 count），
 * 只有在「首次已读」时才 +1，避免重复自增。
 */
final class NoticeLogic
{
    /** 管理侧可写字段白名单 */
    private const FIELDS = ['title', 'type', 'level', 'content', 'status', 'scope', 'publish_at', 'expire_at'];

    /** scope → 目标类型映射（notice_target.target_type） */
    private const SCOPE_TYPES = [
        1 => 'dept',
        2 => 'role',
        3 => 'user',
    ];

    /** 正文净化的标签白名单（与前端 ArtRichEditor 工具栏、utils/richText.ts 保持一致） */
    private const CONTENT_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'strike',
        'h1', 'h2', 'h3', 'h4',
        'ul', 'ol', 'li',
        'blockquote', 'code', 'pre',
        'a', 'img', 'hr', 'span',
    ];

    /** 正文净化的属性白名单：只有链接与图片需要带属性，其余标签一律无属性 */
    private const CONTENT_ATTRS = [
        'a'   => ['href', 'target', 'rel', 'title'],
        'img' => ['src', 'alt', 'title'],
    ];

    // ------------------------------------------------------------------
    // 管理侧
    // ------------------------------------------------------------------

    /** 列头可排序列：前端 prop => 真实列名（未登记的列回落默认排序） */
    private const SORTABLE = [
        // 只留「时间 + 阅读量」：标题/类型/级别/状态/范围排序对运营没有意义
        'publish_at' => 'publish_at',
        'expire_at'  => 'expire_at',
        'read_count' => 'read_count',
    ];

    public static function paginate(array $params): array
    {
        $query = Notice::newScopedQuery();

        if (!empty($params['title'])) {
            $query->where('title', 'like', '%' . $params['title'] . '%');
        }
        // 以下枚举列头均支持多选，值形如 `1,0`
        $statuses = FilterInput::ints($params['status'] ?? null);
        if ($statuses !== []) {
            $query->whereIn('status', $statuses);
        }
        $types = FilterInput::ints($params['type'] ?? null);
        if ($types !== []) {
            $query->whereIn('type', $types);
        }
        $levels = FilterInput::ints($params['level'] ?? null);
        if ($levels !== []) {
            $query->whereIn('level', $levels);
        }
        $scopes = FilterInput::ints($params['scope'] ?? null);
        if ($scopes !== []) {
            $query->whereIn('scope', $scopes);
        }
        // 创建时间范围（列头时间筛选，值已归一化为 Y-m-d H:i:s）
        [$start, $end] = FilterInput::range($params['start'] ?? null, $params['end'] ?? null);
        if ($start !== '') {
            $query->where('create_time', '>=', $start);
        }
        if ($end !== '') {
            $query->where('create_time', '<=', $end);
        }
        // 发布时间范围：与创建时间各用一组 startKey / endKey，互不干扰
        [$publishStart, $publishEnd] = FilterInput::range(
            $params['publish_start'] ?? null,
            $params['publish_end'] ?? null
        );
        if ($publishStart !== '') {
            $query->where('publish_at', '>=', $publishStart);
        }
        if ($publishEnd !== '') {
            $query->where('publish_at', '<=', $publishEnd);
        }
        // 到期时间范围
        [$expireStart, $expireEnd] = FilterInput::range(
            $params['expire_start'] ?? null,
            $params['expire_end'] ?? null
        );
        if ($expireStart !== '') {
            $query->where('expire_at', '>=', $expireStart);
        }
        if ($expireEnd !== '') {
            $query->where('expire_at', '<=', $expireEnd);
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        SortInput::apply($query, $params, self::SORTABLE, 'level', 'desc');
        $list = $query->page($page, $limit)->select()->toArray();

        return ['total' => $total, 'list' => $list];
    }

    public static function read(int $id): array
    {
        $notice = Notice::where('id', $id)->find();
        if (!$notice) {
            throw new ApiException(I18n::t('notice.not_found'), 404);
        }

        $row = $notice->toArray();
        $row['scope'] = (int)($row['scope'] ?? 0);
        $row['target_ids'] = $row['scope'] === 0
            ? []
            : array_map('intval', NoticeTarget::where('notice_id', $id)->column('target_id'));

        return $row;
    }

    public static function create(array $data, UserContext $operator): int
    {
        $scope     = self::normalizeScope($data['scope'] ?? 0);
        $targetIds = self::normalizeTargetIds($scope, $data['target_ids'] ?? []);

        $payload = self::prepare($data);
        $payload['scope']      = $scope;
        $payload['create_by']  = $operator->id;
        $payload['read_count'] = 0;
        // 已发布但没填发布时间的，视为「立即发布」
        if ((int)$payload['status'] === 1 && empty($payload['publish_at'])) {
            $payload['publish_at'] = date('Y-m-d H:i:s');
        }

        // 公告与投放目标同成败：否则会出现「公告存在但谁都看不到」
        return (int)Db::transaction(static function () use ($payload, $scope, $targetIds): int {
            $id = (int)Notice::withoutGlobalScope()->insertGetId($payload);
            self::syncTargets($id, $scope, $targetIds);

            return $id;
        });
    }

    public static function update(int $id, array $data): void
    {
        self::read($id);
        $payload = self::prepare($data, true);
        if ((int)($payload['status'] ?? 0) === 1 && empty($payload['publish_at'])) {
            $payload['publish_at'] = date('Y-m-d H:i:s');
        }

        $hasScope = array_key_exists('scope', $data);
        $scope    = $hasScope ? self::normalizeScope($data['scope'] ?? 0) : 0;
        $targets  = $hasScope ? self::normalizeTargetIds($scope, $data['target_ids'] ?? []) : [];

        Db::transaction(static function () use ($id, $payload, $hasScope, $scope, $targets): void {
            Notice::where('id', $id)->update($payload);

            // 定向投放：只要传了 scope 就重设目标（scope=0 视为清空投放）
            if ($hasScope) {
                self::syncTargets($id, $scope, $targets);
            }
        });
    }

    public static function delete(int $id): void
    {
        self::read($id);
        Db::transaction(static function () use ($id): void {
            // 软删除：进回收站（见 RecycleLogic 的 notice 类型）
            Notice::destroy($id);
            NoticeTarget::where('notice_id', $id)->delete();
            NoticeRead::where('notice_id', $id)->delete();
        });
    }

    /**
     * 已读回执统计报表。
     *
     * 返回该公告的「应读 / 已读 / 未读」人数，以及明细列表：
     *   - `view=read`（默认）返回已读明细（含 read_time，按时间倒序）；
     *   - `view=unread` 返回未读明细（应读但尚未读的用户）。
     *
     * 应读用户口径与阅读侧 `targetedNoticeIds` 一致：
     *   全部用户 = 所有未删用户；指定部门 = 部门（含下级）下的用户；
     *   指定角色 = 该角色（含后代角色）下的用户；指定用户 = 直接指定的用户。
     */
    public static function readReport(int $noticeId, array $params): array
    {
        $notice = Notice::where('id', $noticeId)->find();
        if (!$notice) {
            throw new ApiException(I18n::t('notice.not_found'), 404);
        }
        $notice = $notice->toArray();

        $targetUserIds = self::targetUserIds($notice);
        $readUserIds   = array_map('intval', NoticeRead::where('notice_id', $noticeId)->column('user_id'));

        $view  = (string)($params['view'] ?? 'read');
        ['page' => $page, 'limit' => $limit] = PageInput::of($params);

        if ($view === 'unread') {
            $unreadIds = array_values(array_diff($targetUserIds, $readUserIds));
            $list      = self::userRows(array_slice($unreadIds, ($page - 1) * $limit, $limit));
        } else {
            $rows = NoticeRead::where('notice_id', $noticeId)
                ->order('read_time', 'desc')->order('id', 'desc')
                ->page($page, $limit)->select()->toArray();
            $list = self::readRows($rows);
        }

        return [
            'notice' => [
                'id'    => (int)$notice['id'],
                'title' => (string)$notice['title'],
                'scope' => (int)($notice['scope'] ?? 0),
            ],
            'total'  => count($targetUserIds),
            'read'   => count($readUserIds),
            'unread' => max(0, count($targetUserIds) - count($readUserIds)),
            'list'   => $list,
        ];
    }

    /**
     * 定向投放的「指定用户」候选（模糊搜索，默认无数据）。
     *
     * 规则配置、公告投放都属于系统配置界面（不是业务数据浏览），用户选择器需要全量候选，
     * 否则「看不到的用户既选不了、已绑定的也显示成空名字」。与 `DataRuleLogic::searchUsers`
     * 同一口径，直接复用。
     *
     * @param array<int|string> $ids
     * @return array<int,array<string,mixed>>
     */
    public static function searchUsers(string $keyword, array $ids = []): array
    {
        return DataRuleLogic::searchUsers($keyword, $ids);
    }

    /**
     * 投放目标候选：部门树（含下级语义）+ 角色树（含后代语义）。
     *
     * 用户候选不在此返回（可能成千上万），改由 `searchUsers()` 按关键词懒加载。
     * 候选一律取全量：投放属于系统配置动作，不能因为「看不见某个部门/角色」就选不了。
     */
    public static function options(): array
    {
        return [
            'depts' => DeptLogic::treeAll(),
            'roles' => RoleLogic::tree(),
        ];
    }

    // ------------------------------------------------------------------
    // 阅读侧（登录即可）
    // ------------------------------------------------------------------

    /** 我的消息分页（已发布 + 未过期 + 投放给我） */
    public static function myPaginate(int $userId, array $params): array
    {
        $query = self::visible($userId);
        if (!empty($params['only_unread'])) {
            $readIds = NoticeRead::where('user_id', $userId)->column('notice_id');
            if ($readIds) {
                $query->whereNotIn('id', array_map('intval', $readIds));
            }
        }

        ['page' => $page, 'limit' => $limit] = PageInput::of($params);
        $total = $query->count();
        $rows  = $query->page($page, $limit)->order('level', 'desc')->order('id', 'desc')->select()->toArray();

        // 标记已读状态（一次查询，避免逐行查库）
        $ids     = array_map('intval', array_column($rows, 'id'));
        $readIds = $ids === []
            ? []
            : array_map('intval', NoticeRead::where('user_id', $userId)->whereIn('notice_id', $ids)->column('notice_id'));
        foreach ($rows as &$row) {
            $row['is_read'] = in_array((int)$row['id'], $readIds, true);
        }
        unset($row);

        return ['total' => $total, 'list' => $rows];
    }

    /** 未读数量（顶栏角标） */
    public static function unreadCount(int $userId): int
    {
        $visibleIds = array_map('intval', self::visible($userId)->column('id'));
        if ($visibleIds === []) {
            return 0;
        }

        // 只统计「当前可见公告」里的已读：已过期 / 已删除公告的已读记录不能把未读数冲成 0
        $readIds = array_map('intval', NoticeRead::where('user_id', $userId)
            ->whereIn('notice_id', $visibleIds)
            ->column('notice_id'));

        return count(array_diff($visibleIds, $readIds));
    }

    public static function markRead(int $userId, int $noticeId): void
    {
        $notice = self::visible($userId)->where('id', $noticeId)->find();
        if (!$notice) {
            throw new ApiException(I18n::t('notice.not_found_or_expired'), 404);
        }

        // 影响行数决定是否 +1：并发 / 双击时只有真正插入的那次计数
        if (self::insertRead($noticeId, $userId) > 0) {
            Notice::where('id', $noticeId)->inc('read_count')->update();
        }
    }

    /** 全部标记已读 */
    public static function markAllRead(int $userId): int
    {
        $ids = array_map('intval', self::visible($userId)->column('id'));
        if ($ids === []) {
            return 0;
        }

        $read = array_map('intval', NoticeRead::where('user_id', $userId)->whereIn('notice_id', $ids)->column('notice_id'));
        $todo = array_values(array_diff($ids, $read));
        if ($todo === []) {
            return 0;
        }

        $inserted = [];
        foreach ($todo as $id) {
            if (self::insertRead($id, $userId) > 0) {
                $inserted[] = $id;
            }
        }

        if ($inserted !== []) {
            Notice::whereIn('id', $inserted)->inc('read_count')->update();
        }

        return count($inserted);
    }

    /**
     * 插入一条已读记录，返回 1 = 本次真正写入，0 = 已存在（含并发写入）。
     *
     * 用 INSERT IGNORE 而不是「先查后插」：唯一键 uk_notice_user 保证幂等，
     * 并发下不会抛重复键，read_count 也只加一次。
     */
    private static function insertRead(int $noticeId, int $userId): int
    {
        return (int)Db::execute(
            'INSERT IGNORE INTO `' . Db::name('notice_read')->getTable() . '` (`notice_id`, `user_id`, `read_time`) VALUES (?, ?, ?)',
            [$noticeId, $userId, date('Y-m-d H:i:s')]
        );
    }

    // ------------------------------------------------------------------
    // 查询
    // ------------------------------------------------------------------

    /** 该用户可见的公告（已发布 + 未过期 + 投放给他） */
    private static function visible(int $userId): BaseQuery
    {
        $now = date('Y-m-d H:i:s');

        $query = Notice::newScopedQuery()
            ->where('status', 1)
            ->where(function ($query) use ($now): void {
                $query->whereNull('publish_at')->whereOr('publish_at', '<=', $now);
            })
            ->where(function ($query) use ($now): void {
                $query->whereNull('expire_at')->whereOr('expire_at', '>', $now);
            });

        // 定向投放：scope=0 对所有人可见；scope!=0 只对命中的目标可见
        $targetIds = self::targetedNoticeIds($userId);
        $query->where(function ($query) use ($targetIds): void {
            $query->where('scope', 0);
            if ($targetIds !== []) {
                $query->whereOr('id', 'in', $targetIds);
            }
        });

        return $query;
    }

    /** 当前用户命中的「定向投放」公告 id（scope != 0）。 */
    private static function targetedNoticeIds(int $userId): array
    {
        $ids = [];

        // 指定用户
        $ids = array_merge($ids, NoticeTarget::where('target_type', 'user')->where('target_id', $userId)->column('notice_id'));

        // 指定角色（有效角色 = 直连 + 祖先，与鉴权同口径）
        $roleIds = AuthService::effectiveRoleIds($userId);
        if ($roleIds !== []) {
            $ids = array_merge($ids, NoticeTarget::where('target_type', 'role')->whereIn('target_id', $roleIds)->column('notice_id'));
        }

        // 指定部门（含下级）
        $deptIds = self::userDeptSubtree($userId);
        if ($deptIds !== []) {
            $ids = array_merge($ids, NoticeTarget::where('target_type', 'dept')->whereIn('target_id', $deptIds)->column('notice_id'));
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    /** 该公告的「应读用户」集合（报表用，与阅读侧同口径）。 */
    private static function targetUserIds(array $notice): array
    {
        $scope    = (int)($notice['scope'] ?? 0);
        $noticeId = (int)$notice['id'];

        if ($scope === 0) {
            // 「应读用户」= 全量启用用户，与**操作者的数据范围无关**：
            // 公告是广播给全站的，报表口径必须覆盖所有人，否则回执统计会凭空少人。
            // 因此显式跳出数据权限（withoutGlobalScope），逃生口在此处可见且有必要。
            return array_map('intval', User::withoutGlobalScope()->column('id'));
        }

        $ids = array_map('intval', NoticeTarget::where('notice_id', $noticeId)->column('target_id'));
        if ($ids === []) {
            return [];
        }

        if ($scope === 3) {
            return $ids;   // 指定用户
        }

        if ($scope === 2) {
            // 指定角色：该角色及其后代角色下的用户
            $roles = self::roleSubtreeIds($ids);

            return array_map('intval', UserRole::whereIn('role_id', $roles)->column('user_id'));
        }

        // scope === 1：指定部门（含下级）
        $depts = self::deptSubtree($ids);

        return array_map('intval', UserDept::whereIn('dept_id', $depts)->column('user_id'));
    }

    // ------------------------------------------------------------------
    // 工具
    // ------------------------------------------------------------------

    /** @param array<int,array<string,mixed>> $rows */
    private static function readRows(array $rows): array
    {
        $userIds = array_map('intval', array_column($rows, 'user_id'));
        // 回执明细要显示「谁读了」，必须是全量用户信息：
        // 若按操作者的数据范围收窄，范围外的已读人会被显示成空白，报表即失真。
        $nick    = $userIds === [] ? [] : User::withoutGlobalScope()->whereIn('id', $userIds)->column('nickname', 'id');
        $name    = $userIds === [] ? [] : User::withoutGlobalScope()->whereIn('id', $userIds)->column('username', 'id');

        $out = [];
        foreach ($rows as $row) {
            $uid    = (int)$row['user_id'];
            $out[] = [
                'user_id'   => $uid,
                'username'  => (string)($name[$uid] ?? ''),
                'nickname'  => (string)($nick[$uid] ?? ''),
                'read_time' => (string)($row['read_time'] ?? ''),
            ];
        }

        return $out;
    }

    /** @param array<int,int> $userIds */
    private static function userRows(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $out = [];
        foreach (User::withoutGlobalScope()->whereIn('id', $userIds)->field('id,username,nickname,status')->select()->toArray() as $row) {
            $out[] = [
                'user_id'   => (int)$row['id'],
                'username'  => (string)$row['username'],
                'nickname'  => (string)$row['nickname'],
                'read_time' => '',
            ];
        }

        return $out;
    }

    /** 我所属部门及其所有下级。 */
    private static function userDeptSubtree(int $userId): array
    {
        $deptIds = array_map('intval', UserDept::where('user_id', $userId)->column('dept_id'));

        return self::deptSubtree($deptIds);
    }

    /** 部门子树展开：给定部门 + 其所有下级部门。 */
    private static function deptSubtree(array $ids): array
    {
        // 子树展开需要**完整**部门树（父不在可见集合时也要能往上/往下走），故跳出数据权限
        return Tree::subtreeIds(Dept::withoutGlobalScope()->column('parent_id', 'id'), $ids);
    }

    /** 角色子树展开：给定角色 + 其所有后代角色。 */
    private static function roleSubtreeIds(array $ids): array
    {
        return Tree::subtreeIds(Role::withoutGlobalScope()->column('parent_id', 'id'), $ids);
    }

    private static function syncTargets(int $noticeId, int $scope, array $targetIds): void
    {
        NoticeTarget::where('notice_id', $noticeId)->delete();
        if ($scope === 0 || $targetIds === []) {
            return;
        }

        $type = self::SCOPE_TYPES[$scope] ?? 'user';
        $rows = [];
        foreach ($targetIds as $targetId) {
            $rows[] = [
                'notice_id'   => $noticeId,
                'target_type' => $type,
                'target_id'   => $targetId,
            ];
        }
        NoticeTarget::insertAll($rows);
    }

    private static function normalizeScope(mixed $scope): int
    {
        $scope = (int)$scope;

        return in_array($scope, [0, 1, 2, 3], true) ? $scope : 0;
    }

    /** @return array<int,int> */
    private static function normalizeTargetIds(int $scope, mixed $targetIds): array
    {
        if ($scope === 0 || !is_array($targetIds)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map('intval', $targetIds),
            static fn ($id) => $id > 0
        )));
    }

    /**
     * 正文 HTML 白名单净化（保存侧兜底）。
     *
     * **为什么不用第三方库**（如 ezyang/htmlpurifier）：
     *   1. 本项目 composer 依赖刻意保持精简，为了一个正文字段引入一个重量级依赖不划算；
     *   2. 真正的第一道防线在**渲染侧**：前端渲染前统一过 `utils/richText.ts` 的 DOMPurify，
     *      后端这次清洗只是「别把明显可执行的片段存进库」的兜底，
     *      历史数据 / 绕过接口直接写库的内容由渲染侧拦住。
     *
     * 因此这里用**正则白名单**实现，只保证三件事：
     *   ① 危险元素（script / iframe / style 等）连同内容整体剔除；
     *   ② 白名单外的标签只脱掉标签本身，保留文字内容；
     *   ③ 只保留白名单属性，且 href / src 拒绝 javascript: / vbscript: / data: 协议。
     * 正则方案挡不住畸形标签与 mXSS，这一点由渲染侧的 DOMPurify 兜住。
     */
    private static function sanitizeContent(string $html): string
    {
        if ($html === '') {
            return '';
        }

        // ① 危险元素连同内容整体剔除（含嵌套内容）
        $html = (string)preg_replace(
            '#<(script|style|iframe|object|embed|form|template|svg|math)\b[^>]*>.*?</\1\s*>#is',
            '',
            $html
        );

        // ② 标签白名单：白名单外的标签脱掉标签本身，保留内部文字
        $allowed = implode('|', self::CONTENT_TAGS);
        $html    = (string)preg_replace('#<(?!/?(?:' . $allowed . ')\b)[^>]*>#is', '', $html);

        // ③ 属性白名单：逐标签重写属性，非白名单属性（含 on* 事件、style）直接丢弃
        $html = (string)preg_replace_callback(
            '#<(' . $allowed . ')((?:\s+[^>]*)?)\s*/?>#is',
            static function (array $matches): string {
                $tag         = strtolower($matches[1]);
                $attrs       = self::filterContentAttrs($tag, $matches[2] ?? '');
                $selfClosing = in_array($tag, ['br', 'img', 'hr'], true);

                return '<' . $tag . $attrs . ($selfClosing ? ' />' : '>');
            },
            $html
        );

        return trim($html);
    }

    /** 只保留该标签在白名单内的属性，并对属性值做转义 */
    private static function filterContentAttrs(string $tag, string $raw): string
    {
        $allowed = self::CONTENT_ATTRS[$tag] ?? [];
        if ($allowed === [] || trim($raw) === '') {
            return '';
        }

        $out = '';
        $hit = preg_match_all(
            '#([a-z][a-z0-9:_-]*)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s"\'>]+))#i',
            $raw,
            $matches,
            PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL
        );
        if (!$hit) {
            return '';
        }

        foreach ($matches as $match) {
            $name = strtolower($match[1]);
            if (!in_array($name, $allowed, true)) {
                continue;
            }
            // 先解码再判断协议，避免 &#106;avascript: 这类实体编码绕过
            $value = html_entity_decode((string)($match[2] ?? $match[3] ?? $match[4] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (in_array($name, ['href', 'src'], true) && preg_match('#^\s*(?:javascript|vbscript|data)\s*:#i', $value)) {
                continue;
            }
            $out .= ' ' . $name . '="' . htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"';
        }

        return $out;
    }

    /**
     * 字段白名单整理。
     *
     * @param bool $partial 更新场景：只保留显式传入的字段
     * @return array<string,mixed>
     */
    private static function prepare(array $data, bool $partial = false): array
    {
        $payload = FilterInput::only($data, self::FIELDS);

        // 标题：新增必填；更新时若显式传入则不能为空
        if (!$partial || array_key_exists('title', $payload)) {
            $title = trim((string)($payload['title'] ?? ''));
            if ($title === '') {
                throw new ApiException(I18n::t('notice.title_required'), 422);
            }
            $payload['title'] = $title;
        }

        // 正文：富文本 HTML，保存前做一次白名单净化（详见 sanitizeContent 的说明）
        if (array_key_exists('content', $payload)) {
            $payload['content'] = self::sanitizeContent((string)($payload['content'] ?? ''));
        }

        // 投放范围归一化（非法值回退到「全部用户」）
        if (array_key_exists('scope', $payload)) {
            $payload['scope'] = self::normalizeScope($payload['scope']);
        }

        // 空字符串的时间字段视为「不设置」，否则 MySQL 严格模式会报错
        foreach (['publish_at', 'expire_at'] as $timeField) {
            if (array_key_exists($timeField, $payload) && $payload[$timeField] === '') {
                $payload[$timeField] = null;
            }
        }

        return $payload;
    }
}
