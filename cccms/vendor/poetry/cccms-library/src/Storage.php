<?php

declare(strict_types=1);

namespace cccms;

use think\{App, Container, Validate};
use cccms\model\SysFile;
use cccms\model\SysFileCate;
use cccms\services\ConfigService;

/**
 * 文件存储
 * Library for ThinkAdmin
 * https://gitee.com/zoujingli/ThinkLibrary
 */
abstract class Storage
{
    /**
     * @var App
     */
    protected App $app;

    /**
     * @var SysFile
     */
    protected SysFile $model;

    /**
     * Service constructor.
     * @param App $app
     */
    public function __construct(App $app)
    {
        $this->app = $app;
        $this->model = SysFile::mk();
    }

    /**
     * 设置文件驱动名称
     * @param null|string $name 驱动名称
     */
    public static function instance(?string $name = null, ?string $class = null)
    {
        if (is_null($class)) {
            $type = ucfirst(strtolower($name ?: 'local'));
            $class = 'cccms\\storages\\' . $type . 'Storage';
        }
        if (class_exists($class)) {
            return Container::getInstance()->make($class);
        } else {
            return _result(['code' => 404, 'msg' => 'File driver [' . $class . '] does not exist.'], _getEnCode());
        }
    }

    /**
     * 获取类别别名
     * @param int $cate_id 类别ID
     * @return string
     */
    public function getCatePath(int $cate_id = 0): string
    {
        return SysFileCate::mk()->where('id', $cate_id)->value('cate_name') ?: 'default';
    }

    /**
     * 获取local磁盘路径
     */
    public function getLocalPath(): string
    {
        return $this->app->getRootPath() . 'public/uploads/';
    }

    /**
     * 文件查询
     */
    public function query(): void
    {
        $code = $this->app->request->param('code', '');
        if (empty($code)) {
            _result(['code' => 404, 'msg' => '附件访问码不能为空'], _getEnCode());
        }
        // 这里需要关闭权限数据验证 有些地方无法登录
        $file = SysFile::mk()->where(['file_code' => $code])->findOrEmpty();
        if ($file->isEmpty()) {
            _result(['code' => 404, 'msg' => '附件不存在'], _getEnCode());
        }
        if ($file->status === 0) {
            _result(['code' => 403, 'msg' => '禁止访问附件'], _getEnCode());
        }
        $pass = $this->app->request->param('pass', '');
        if ($pass !== $file->extract_code) {
            _result(['code' => 403, 'msg' => '提取码错误'], _getEnCode());
        }
        // 获取磁盘类型
        $diskPath = ConfigService::instance()->getConfig('storage.diskType', 'local');
        if ($diskPath === 'local') $diskPath = $this->getLocalPath();
        $diskPath = str_replace(['\\', '//'], ['/', '/'], $diskPath . '/' . $file['file_url']);
        // 判断附件是否在磁盘中
        if (!$resFile = @readfile($diskPath)) {
            _result(['code' => 404, 'msg' => '无法访问附件'], _getEnCode());
        }
        _result(['code' => 200, 'msg' => 'success', 'data' => $resFile], 'view', [
            'Content-Type' => $file['file_mime'] . ';',
            'Content-Disposition' => 'filename=' . urlencode($file['file_name']),
        ]);
    }

    /**
     * 文件验证 支持多文件验证
     * @param $files
     * @return array
     */
    public function validateFile($files): array
    {
        if (empty($files)) {
            _result(['code' => 404, 'msg' => '上传文件不存在'], _getEnCode());
        }
        $storage = ConfigService::instance()->getConfig('storage');
        $fileSize = $storage['uploadSize'] * 1024 * 1024;
        $fileExt = implode(',', $storage['uploadExt']);
        $rules = ['file' => 'fileSize:' . $fileSize . '|fileExt:' . $fileExt];
        $validate = new Validate;
        if (!$validate->rule($rules)->check(['file' => $files])) {
            _result(['code' => 412, 'msg' => $validate->getError()], _getEnCode());
        }
        // $rule = ['file' => 'fileSize:' . $fileSize . '|fileExt:' . $fileExt];
        // validate($rule)->check(['file' => $files]);
        $res = [];
        // 判断是否多文件上传
        if (is_array($files)) {
            // 多文件
            foreach ($files as $val) {
                $this->safeFile($val);
                $res[] = $val;
            }
        } else {
            // 单文件
            $this->safeFile($files);
            $res[] = $files;
        }
        return $res;
    }

    /**
     * 安全检查
     * @param $file
     */
    public function safeFile($file)
    {
        if (!is_uploaded_file($file->getPathname())) {
            _result(['code' => 412, 'msg' => '这不是一个上传文件'], _getEnCode());
        }
        // 后缀
        $extension = strtolower($file->getOriginalExtension());
        $saveName = input('key') ?: $this->name($file->getPathname(), $extension, '', 'md5_file');
        // 检查文件后缀是否被恶意修改
        if (ltrim(strtolower(strrchr($saveName, '.')), '.') !== $extension) {
            _result(['code' => 412, 'msg' => '文件后缀异常，请重新上传文件！'], _getEnCode());
        }
        if (in_array($extension, ['sh', 'asp', 'bat', 'cmd', 'exe', 'php'])) {
            _result(['code' => 412, 'msg' => '文件安全保护，禁止上传可执行文件！'], _getEnCode());
        }
        if (in_array($extension, ['jpg', 'gif', 'png', 'bmp', 'jpeg', 'wbmp'])) {
            if ($this->imgNotSafe($file->getPathname())) {
                _result(['code' => 412, 'msg' => '图片未通过安全检查！'], _getEnCode());
            }
            [$width, $height] = getimagesize($file->getPathname());
            if (($width < 1 || $height < 1)) {
                _result(['code' => 412, 'msg' => '读取图片的尺寸失败！'], _getEnCode());
            }
        }
    }

    /**
     * 获取文件相对名称
     * @param string $url 文件访问链接
     * @param string $ext 文件后缀名称
     * @param string $pre 文件存储前缀
     * @param string $fun 名称规则方法
     * @return string
     */
    public static function name(string $url, string $ext = '', string $pre = '', string $fun = 'md5'): string
    {
        [$hah, $ext] = [$fun($url), trim($ext ?: pathinfo($url, 4), '.\\/')];
        $attr = [trim($pre, '.\\/'), substr($hah, 0, 2), substr($hah, 2, 30)];
        return trim(join('/', $attr), '/') . '.' . strtolower($ext ?: 'tmp');
    }

    /**
     * 检查图片是否安全
     * @param string $filename
     * @return boolean
     */
    private function imgNotSafe(string $filename): bool
    {
        $source = fopen($filename, 'rb');
        if (($size = filesize($filename)) > 512) {
            $hex = bin2hex(fread($source, 512));
            fseek($source, $size - 512);
            $hex .= bin2hex(fread($source, 512));
        } else {
            $hex = bin2hex(fread($source, $size));
        }
        if (is_resource($source)) fclose($source);
        $bins = hex2bin($hex);
        /* 匹配十六进制中的 <% ( ) %> 或 <? ( ) ?> 或 <script | /script> */
        foreach (['<?php ', '<% ', '<script '] as $key) if (stripos($bins, $key) !== false) return true;
        return (bool)preg_match("/(3c25.*?28.*?29.*?253e)|(3c3f.*?28.*?29.*?3f3e)|(3C534352495054)|(2F5343524950543E)|(3C736372697074)|(2F7363726970743E)/is", $hex);
    }

    /**
     * 根据文件后缀获取文件MINE
     * @param array|string $exts 文件后缀
     * @param array $mime 文件信息
     * @return string
     */
    public static function mime($exts, array $mime = []): string
    {
        $mimes = static::mimes();
        foreach (is_string($exts) ? explode(',', $exts) : $exts as $ext) {
            $mime[] = $mimes[strtolower($ext)] ?? 'application/octet-stream';
        }
        return join(',', array_unique($mime));
    }

    /**
     * 获取所有文件的信息
     * @return array
     */
    public static function mimes(): array
    {
        static $mimes = [];
        if (count($mimes) > 0) return $mimes;
        return $mimes = include __DIR__ . '/storages/bin/mimes.php';
    }

    /**
     * 上传文件
     * @param $files
     * @param int|string $pathOrId int 则为文件类型ID，string则为文件夹名称
     * @return mixed
     */
    abstract public function upload($files, $pathOrId = 0);

    /**
     * 删除文件
     * @param int|string $pathOrId int 则为文件类型ID，string则为文件夹名称
     * @return mixed
     */
    abstract public function delete(int $pathOrId);

    /**
     * 文件列表
     * @return mixed
     */
    abstract public function read();
}
