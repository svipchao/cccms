<?php
/**
 * This file is part of webman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

// 本项目用 JWT + support/OnlineSession 维护会话，不使用 PHP Session，
// 因此不注册 webman 的 Session 引导（注册它会要求 config/session.php 必须存在）。
return [];
