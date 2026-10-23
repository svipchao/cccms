<?php

declare(strict_types=1);

namespace plugin\cccms\app\logic;

use plugin\cccms\app\model\Dept;
use plugin\cccms\app\model\Role;
use plugin\cccms\app\model\User;
use plugin\cccms\app\model\UserDept;
use plugin\cccms\support\ApiException;
use plugin\cccms\support\AuthService;
use plugin\cccms\support\FilterInput;
use plugin\cccms\support\I18n;
use plugin\cccms\support\OnlineSession;
use plugin\cccms\support\PasswordPolicy;
use plugin\cccms\support\TokenBlacklist;
use plugin\cccms\support\UserContext;

/**
 * 个人中心：登录用户查看 / 维护**自己**的资料。
 *
 * 与 UserLogic 的区别：这里始终以 `$user->id` 为目标，不接受外部传入的 id，
 * 因此不需要数据权限判定，也不会因为误传 id 改到别人。
 *
 * 这里**统一用 `User::withoutGlobalScope()` 显式跳出数据权限**：
 * 「本部门」档且自己没被分配任何部门时，预设基线是 fail-closed（查不到任何用户），
 * 若走作用域，用户会连自己的资料都读不到、改不了。
 */
final class ProfileLogic
{
    /**
     * 允许本人修改的字段（白名单）。
     *
     * 刻意不放开 username / status / 角色 / 部门 / 岗位——那些属于用户管理，
     * 必须在 `cccms:user:update` 权限下由管理员操作。
     */
    private const FIELDS = ['nickname', 'avatar', 'email', 'phone'];

    public static function read(UserContext $user): array
    {
        $row = User::withoutGlobalScope()
            ->where('id', $user->id)
            ->field('id,username,nickname,avatar,email,phone,status,remark,login_time,login_ip,create_time')
            ->find();

        if (!$row) {
            throw new ApiException(I18n::t('profile.account_invalid'), 404);
        }

        $data          = $row->toArray();
        $data['roles'] = self::roleNames($user->id);
        $data['depts'] = self::deptNames($user->id);

        return $data;
    }

    public static function update(UserContext $user, array $data): void
    {
        $data = FilterInput::only($data, self::FIELDS);

        if (array_key_exists('nickname', $data)) {
            $nickname = trim((string)$data['nickname']);
            if ($nickname === '') {
                throw new ApiException(I18n::t('profile.nickname_required'), 422);
            }
            $data['nickname'] = mb_substr($nickname, 0, 64);
        }

        if (array_key_exists('email', $data)) {
            $email = trim((string)$data['email']);
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new ApiException(I18n::t('profile.email_invalid'), 422);
            }
            $data['email'] = mb_substr($email, 0, 128);
        }

        if (array_key_exists('phone', $data)) {
            $phone = trim((string)$data['phone']);
            if ($phone !== '' && !preg_match('/^[0-9+\-\s]{5,32}$/', $phone)) {
                throw new ApiException(I18n::t('profile.phone_invalid'), 422);
            }
            $data['phone'] = $phone;
        }

        if (array_key_exists('avatar', $data)) {
            $data['avatar'] = mb_substr(trim((string)$data['avatar']), 0, 255);
        }

        if ($data === []) {
            return;
        }

        User::withoutGlobalScope()->where('id', $user->id)->update($data);
    }

    /**
     * 修改自己的密码。
     *
     * 改密成功后**作废其它设备上的令牌**：密码泄露场景下，攻击者手里的旧令牌
     * 若继续有效到自然过期，改密就等于没改。当前设备（`$currentJti`）保留，
     * 避免用户改完密码立刻被踢下线；`$currentJti` 为空时无法区分当前设备，跳过清理。
     */
    public static function changePassword(UserContext $user, string $old, string $new, string $currentJti = ''): void
    {
        if ($old === '' || $new === '') {
            throw new ApiException(I18n::t('profile.password_required'), 422);
        }
        // 身份信息用于拒绝「新密码包含用户名/昵称/邮箱」这类可猜口令
        PasswordPolicy::assertValid($new, [
            'username' => $user->username,
            'nickname' => $user->nickname,
        ]);

        $hash = (string)User::withoutGlobalScope()->where('id', $user->id)->value('password');
        if ($hash === '' || !password_verify($old, $hash)) {
            throw new ApiException(I18n::t('profile.old_password_incorrect'), 422);
        }
        if ($old === $new) {
            throw new ApiException(I18n::t('profile.password_same'), 422);
        }

        User::withoutGlobalScope()->where('id', $user->id)->update([
            'password' => password_hash($new, PASSWORD_BCRYPT),
        ]);

        self::revokeOtherSessions($user->id, $currentJti);
    }

    /**
     * 改密后作废该用户除当前设备外的全部令牌（单令牌黑名单 + 在线列表同步移除）。
     *
     * 不用用户级分界线（`revokeUser()`）：分界线会把**当前**令牌一并作废。
     */
    private static function revokeOtherSessions(int $userId, string $currentJti): void
    {
        if ($currentJti === '') {
            // 拿不到当前 jti 时无法区分「自己」，宁可保留全部会话也不误踢用户
            return;
        }

        foreach (OnlineSession::listOfUser($userId) as $row) {
            $jti = (string)($row['jti'] ?? '');
            if ($jti === '' || $jti === $currentJti) {
                continue;
            }

            TokenBlacklist::revoke($jti, OnlineSession::expiryOf($jti));
            OnlineSession::remove($jti);
        }
    }

    /**
     * 我的在线会话（登录设备）。
     *
     * 只返回 `user_id = 当前用户` 的会话，并标注哪一条是**当前设备** ——
     * 前端据此禁用「注销」按钮（与 `OnlineController` 同一约定：
     * 下线自己请用「退出登录」，否则会在无感知的情况下把自己踢出去）。
     *
     * @param string $currentJti 当前请求的会话 ID
     */
    public static function sessions(UserContext $user, string $currentJti): array
    {
        $list = OnlineSession::listOfUser($user->id);

        foreach ($list as &$row) {
            $row['current'] = $currentJti !== '' && $row['jti'] === $currentJti;
        }
        unset($row);

        return $list;
    }

    /**
     * 注销我的一条登录设备。
     *
     * 「下线」要在两处同时生效（见 `OnlineLogic`）：从在线列表移除 + 让已签发的令牌失效。
     * 归属校验失败时统一返回「会话不存在或已失效」，不区分「不存在」与「不是我的」，
     * 避免被用来探测他人会话是否存在。
     */
    public static function revokeSession(UserContext $user, string $jti, string $currentJti): void
    {
        if ($jti === '') {
            throw new ApiException(I18n::t('profile.device_required'), 422);
        }
        if ($currentJti !== '' && $jti === $currentJti) {
            throw new ApiException(I18n::t('profile.current_device_forbidden'), 422);
        }
        if (OnlineSession::ownerOf($jti) !== $user->id) {
            throw new ApiException(I18n::t('profile.session_invalid'), 404);
        }

        TokenBlacklist::revoke($jti, OnlineSession::expiryOf($jti));
        OnlineSession::remove($jti);
    }

    /** 我拥有的角色名（含继承来的祖先角色，与鉴权口径一致） */
    private static function roleNames(int $userId): array
    {
        $ids = AuthService::effectiveRoleIds($userId);
        if ($ids === []) {
            return [];
        }

        return array_values(Role::whereIn('id', $ids)->order('sort', 'asc')->column('name'));
    }

    /** 我所属的部门名 */
    private static function deptNames(int $userId): array
    {
        $ids = array_map('intval', UserDept::where('user_id', $userId)->column('dept_id'));
        if ($ids === []) {
            return [];
        }

        // 部门模型参与数据权限：这里必须显式跳出（否则「本部门」档未分配部门时读不到自己的部门名）
        return array_values(Dept::withoutGlobalScope()
            ->whereIn('id', $ids)
            ->order('sort', 'asc')
            ->column('name'));
    }
}
