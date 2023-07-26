<!DOCTYPE html>
<html lang="zh-cn">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width,user-scalable=no,initial-scale=1.0,maximum-scale=1.0,minimum-scale=1.0">
    <title><?php echo strip_tags($msg) ?: '页面不存在';?></title>
    <style>
        * {
            padding: 0;
            margin: 0;
            background: #fff;
            font-family: "Microsoft Yahei", "Helvetica Neue", Helvetica, Arial, sans-serif;
            color: #333;
            font-size: 16px;
        }

        .system-message {
            padding: 24px 48px;
        }

        .system-message h1 {
            font-size: 100px;
            font-weight: normal;
            line-height: 120px;
            margin-bottom: 12px;
        }

        .system-message .msg {
            line-height: 1.8em;
            font-size: 36px;
        }
    </style>
</head>
<body>
<div class="system-message">
    {php}echo $code == 200 ? '<h1>:)</h1>' : '<h1>:(</h1>';{/php}
    <p class="msg"><?php echo strip_tags($msg);?></p>
    <a id="href" href="javascript:window.history.go(-1);">返回上一页</a>
</div>
</body>
</html>