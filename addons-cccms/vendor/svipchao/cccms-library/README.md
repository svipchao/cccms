### 介绍
cccms-library 是基于 ThinkPHP6.0 封装的一套基础类库。

```
目录结构
    |-extend // 扩展类
    |----ArrExtend.php // 数组操作
    |----FileExtend.php // 文件操作
    |----JwtExtend.php // Jwt
    |-model // 模型
    |-service // 服务
    |----AuthService.php // 授权服务
    |----ConfigService.php // 配置服务
    |----LogService.php // 日志服务
    |----NodeService.php // 节点服务
    |-traits // traits
    |----Controller.php // 基础方法
    |-Base.php 基础类
    |-common.php 公共函数
    |-Library.php Library
    |-Service.php 基础服务
```

### 其他开发资料
```
"repositories": {
    "svipchao/cccms-library": {
        "type": "path",
        "url": "__PATH__\\vendor\\svipchao\\cccms-library",
        "options": {
            "symlink": true
        }
    }
},
```