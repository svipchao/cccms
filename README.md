<h1>CCCMS管理系统</h1>
<p>如果对您有帮助，您可以点右上角 "Star" 支持一下 谢谢！</p>

## 项目介绍

CCCMS是基于 [ThinkPHP8](https://gitee.com/liu21st/thinkphp)
和 [Arco Design Vue](https://github.com/arco-design/arco-design-vue)
开发的一套CMS管理系统，非常适用快速二次开发，内置系统权限管理、系统操作日志等基本功能。满足绝大多数企业日常需求。

## 代码仓库

本项目 为 MIT 协议开源项目，安装使用或二次开发不受约束，欢迎 fork 项目。

部分代码来自互联网，若有异议可以联系作者进行删除。

* Gitee 仓库地址：https://gitee.com/svipchao/cccms
* Github 仓库地址：https://github.com/svipchao/cccms

### 官方网站

[CCCMS](http://www.cccms.cc) | [使用手册(完善中...)](http://doc.cccms.cc)

### 背景

- 为开发者减少重复性的工作，提升开发速度，规范团队开发模式。
- 让企业用更低的成本、更少的人力，更快的速度构建完善的管理后台。

## 功能特色

- 基础系统
    - 路由管理
    - 菜单管理-支持无限类别、分类，自动与权限绑定
    - 日志管理
    - 附件管理

- 权限管理
    - 组织管理-无限级
    - 角色管理-无限级、父子继承机制
    - 数据权限-细分至按钮、数据、字段级别
    - 注解权限-更快的构建权限节点
      ```
      @auth    true  // 是否需要鉴权 无需鉴权的方法,但需要登录
      @login   true  // 是否需要登陆 无需登录的方法,同时也就不需要鉴权了
      @encode  view  // 返回类型编码 支持 view/json/jsonp/xml 注意：view 为视图类型 需要指定模版名
      @methods GET   // 请求类型 请参考 https://www.kancloud.cn/manual/thinkphp6_0/1037520
      ```
- 特性
    - 全面支持RESTFUL架构 支持客户自定义返回数据类型(view/json/jsonp/xml)

## 架构

```
www  WEB部署目录（或者子目录）
├─app           应用目录
│  ├─app_name           应用目录
│  │  ├─common.php      函数文件
├─config                全局配置目录
├─public                WEB目录（对外访问目录）
│  ├─index.php          入口文件
│  ├─router.php         快速测试文件
│  └─.htaccess          用于apache的重写
├─runtime               应用的运行时目录（可写，可定制）
├─vendor                Composer类库目录
├─.example.env          环境变量示例文件
├─composer.json         composer 定义文件
├─LICENSE.txt           授权说明文件
├─README.md             README 文件
├─think                 命令行入口文件
```

## 新手入门

1) git clone https://gitee.com/svipchao/cccms.git
2) composer update

- 参考：https://www.kancloud.cn/manual/thinkphp6_0/1037481

3) 数据库文件

- /cccms/data/install.sql

4) 数据库配置

- /config/database.php

5) 配置网站根目录为/public
6) 后台/admin.php

- 超级管理员账号密码为admin/admin

7) 修改后台登录地址

- 参考：https://www.kancloud.cn/manual/thinkphp6_0/1297876

8) 伪静态请参考 https://www.kancloud.cn/manual/thinkphp6_0/1037488

- PHPStudy Apache伪静态规则
    ```
    <IfModule mod_rewrite.c>
        Options +FollowSymlinks -Multiviews
        RewriteEngine On

        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteRule ^(.*)$ index.php?/$1 [QSA,PT,L]
    </IfModule>
    ```

## 贡献者

感谢所有参与开发的贡献者。[贡献者列表](https://gitee.com/svipchao/cccms/repository/stats/master)

## 特别感谢

ThinkPHP：http://www.thinkphp.cn

FastAdmin：https://gitee.com/karson/fastadmin

ThinkAdmin：https://gitee.com/zoujingli/ThinkAdmin

Arco Design Vue：https://github.com/arco-design/arco-design-vue

## 版权许可

[License MIT](LICENSE)