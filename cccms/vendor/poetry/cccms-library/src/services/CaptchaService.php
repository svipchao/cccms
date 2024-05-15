<?php
declare(strict_types=1);

namespace cccms\services;

use Exception;
use think\Config;
use cccms\Service;
use cccms\extend\{JwtExtend, StrExtend};

class CaptchaService extends Service
{
    private $open = true; // 是否启用验证码
    private $im = null; // 验证码图片实例
    // 验证码文字颜色
    private $color = [
        [255, 106, 106],
        [255, 102, 102],
        [255, 69, 0],
        [255, 48, 48],
        [30, 144, 255],
        [255, 114, 86],
        [72, 118, 255],
        [255, 130, 71],
        [139, 90, 0],
        [102, 102, 255],
        [153, 102, 0],
        [0, 153, 204],
        [0, 51, 102],
        [51, 153, 153],
        [255, 153, 0],
    ];
    // 验证码字符集合
    protected $codeSet = '2345678abcdefhijkmnpqrstuvwxyzABCDEFGHJKLMNPQRTUVWXY';
    // 验证码过期时间（s）
    protected $expire = 60;
    // 使用背景图片
    protected $useImgBg = false;
    // 验证码字体大小(px)
    protected $fontSize = 22;
    // 是否画混淆曲线
    protected $useCurve = true;
    // 是否添加杂点
    protected $useNoise = true;
    // 验证码图片宽度
    protected $imageW = 130;
    // 验证码图片高度
    protected $imageH = 48;
    // 验证码位数
    protected $length = 5;
    // 背景颜色
    protected $bg = [243, 251, 254];
    // 算术验证码
    protected $math = false;
    // 是否区分大小写
    protected $matchCase = true;

    /**
     * 架构方法 设置参数
     * @access public
     */
    public function __construct()
    {
        $config = ConfigService::instance()->getConfig('captcha', []);
        foreach ($config as $key => $val) {
            if (property_exists($this, $key)) $this->{$key} = $val;
        }
    }

    /**
     * 创建验证码
     * @return array
     * @throws Exception
     */
    protected function generate(): array
    {
        $bag = '';
        if ($this->math) {
            $this->length = 5;
            $x = random_int(10, 30);
            $y = random_int(1, 9);
            $bag = "{$x} + {$y} = ";
            $key = $x + $y;
            $key .= '';
        } else {
            $characters = str_split($this->codeSet);
            for ($i = 0; $i < $this->length; $i++) {
                $bag .= $characters[random_int(0, count($characters) - 1)];
            }
            $key = $this->matchCase ? $bag : StrExtend::lower($bag);
        }
        if (!$this->matchCase) $key = StrExtend::lower($key);
        $hash = password_hash($key, PASSWORD_BCRYPT, ['cost' => 10]);
        return ['value' => $bag, 'hash' => $hash];
    }

    /**
     * 验证验证码是否正确
     * @access public
     * @param string $code 用户验证码
     * @return bool 用户验证码是否正确
     */
    public function check(string $code, string $accessToken = ''): bool
    {
        // 调试模式验证码=1234不需要验证
        if (app()->isDebug() && $code == '1234') return true;
        // 没有启用验证码不需要验证
        if (!$this->open) return true;
        $accessToken = JwtExtend::verifyToken($accessToken);
        if (empty($accessToken)) return false;
        // 判断节点是否正确
        if ($accessToken['iss'] !== NodeService::instance()->getCurrentNode()) return false;
        // 验证码是否区分大小写
        if (!$this->matchCase) $code = StrExtend::lower($code);
        return password_verify($code, $accessToken['hash']);
    }

    /**
     * 输出验证码
     * @access public
     * @param string $node 节点
     * @return array
     */
    public function create(string $node = ''): array
    {
        if (!$this->open) return ['open' => false, 'captchaToken' => '', 'base64' => ''];
        $generator = $this->generate();
        // 图片宽(px)
        $this->imageW || $this->imageW = $this->length * $this->fontSize * 1 + $this->length * $this->fontSize / 2;
        // 图片高(px)
        $this->imageH || $this->imageH = $this->fontSize * 2;

        $this->imageW = (int)$this->imageW;
        $this->imageH = (int)$this->imageH;

        // 建立一幅 $this->imageW x $this->imageH 的图像
        $this->im = imagecreate($this->imageW, $this->imageH);
        // 设置背景
        imagecolorallocate($this->im, $this->bg[0], $this->bg[1], $this->bg[2]);

        $fontttf = __DIR__ . '/../../assets/fonts/1.ttf';
        // 绘制背景图片
        if ($this->useImgBg) $this->background();
        // 绘杂点
        if ($this->useNoise) $this->writeNoise();
        // 绘干扰线
        if ($this->useCurve) $this->writeCurve();
        // 绘验证码
        $text = str_split($generator['value']);
        // 横向间距
        $xSpace = $this->math ? 0.6 : ((20 - $this->length * 2) / 10);
        foreach ($text as $index => $char) {
            $x = $this->fontSize * ($index + 0.5) * $xSpace;
            $y = $this->fontSize + mt_rand(10, 20);
            $angle = $this->math ? 0 : mt_rand(-20, 40);

            $color = imagecolorallocate($this->im, ...$this->color[array_rand($this->color)]);
            imagettftext($this->im, (int)$this->fontSize, $angle, (int)$x, (int)$y, $color, $fontttf, $char);
        }

        ob_start();
        // 输出图像
        imagepng($this->im);
        $content = ob_get_clean();
        imagedestroy($this->im);
        $base64 = base64_encode($content);
        $imageData = 'data:' . "image/png" . ';base64,' . $base64;

        // 验证码是否区分大小写
        if (!$this->matchCase) {
            $generator['value'] = StrExtend::lower($generator['value']);
        }
        $accessToken = JwtExtend::getToken([
            'iss' => $node ?: NodeService::instance()->getCurrentNode(),
            'exp' => time() + $this->expire,
            'ip' => request()->ip(),
            'hash' => $generator['hash'],
        ]);
        return ['open' => true, 'captchaToken' => $accessToken, 'base64' => $imageData];
    }

    /**
     * 画一条由两条连在一起构成的随机正弦函数曲线作干扰线(你可以改成更帅的曲线函数)
     *   高中的数学公式咋都忘了涅，写出来
     *     正弦型函数解析式：y=Asin(ωx+φ)+b
     *   各常数值对函数图像的影响：
     *     A：决定峰值（即纵向拉伸压缩的倍数）
     *     b：表示波形在Y轴的位置关系或纵向移动距离（上加下减）
     *     φ：决定波形与X轴位置关系或横向移动距离（左加右减）
     *     ω：决定周期（最小正周期T=2π/∣ω∣）
     */
    protected function writeCurve(): void
    {
        $px = $py = 0;
        // 曲线前部分
        $A = mt_rand(1, (int)($this->imageH / 5)); // 振幅
        $b = mt_rand((int)(-$this->imageH / 4), (int)($this->imageH / 4)); // Y轴方向偏移量
        $f = mt_rand((int)(-$this->imageH / 4), (int)($this->imageH / 4)); // X轴方向偏移量
        $T = mt_rand((int)$this->imageH, (int)$this->imageW * 2); // 周期
        $w = (2 * M_PI) / $T;

        $px1 = 0; // 曲线横坐标起始位置
        $px2 = mt_rand((int)($this->imageW / 2), (int)($this->imageW * 0.8)); // 曲线横坐标结束位置

        $color = imagecolorallocate($this->im, ...$this->color[array_rand($this->color)]);
        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2; // y = Asin(ωx+φ) + b
                $i = (int)($this->fontSize / 5);
                while ($i > 0) {
                    imagesetpixel($this->im, (int)($px + $i), (int)($py + $i), (int)$color); // 这里(while)循环画像素点比imagettftext和imagestring用字体大小一次画出（不用这while循环）性能要好很多
                    $i--;
                }
            }
        }

        // 曲线后部分
        $A = mt_rand(1, (int)($this->imageH / 10)); // 振幅
        $f = mt_rand((int)(-$this->imageH / 4), (int)($this->imageH / 4)); // X轴方向偏移量
        $T = mt_rand((int)$this->imageH, (int)$this->imageW * 2); // 周期
        $w = (2 * M_PI) / $T;
        $b = $py - $A * sin($w * $px + $f) - $this->imageH / 2;
        $px1 = $px2;
        $px2 = $this->imageW;

        for ($px = $px1; $px <= $px2; $px = $px + 1) {
            if (0 != $w) {
                $py = $A * sin($w * $px + $f) + $b + $this->imageH / 2; // y = Asin(ωx+φ) + b
                $i = (int)($this->fontSize / 5);
                while ($i > 0) {
                    imagesetpixel($this->im, (int)($px + $i), (int)($py + $i), $color);
                    $i--;
                }
            }
        }
    }

    /**
     * 画杂点
     * 往图片上写不同颜色的字母或数字
     */
    protected function writeNoise(): void
    {
        $codeSet = '2345678abcdefhijkmnpqrstuvwxyz';
        for ($i = 0; $i < 3; $i++) {
            for ($j = 0; $j < 3; $j++) {
                // 杂点颜色
                $noiseColor = imagecolorallocate($this->im, mt_rand(0, 225), mt_rand(0, 225), mt_rand(0, 225));
                // 绘杂点
                imagestring($this->im, 1, mt_rand(-10, (int)$this->imageW), mt_rand(-10, (int)$this->imageH), $codeSet[mt_rand(0, 29)], $noiseColor);
            }
        }
    }

    /**
     * 绘制背景图片
     * 注：如果验证码输出图片比较大，将占用比较多的系统资源
     */
    protected function background(): void
    {
        $path = __DIR__ . '/../../assets/bgs/';
        $dir = dir($path);

        $bgs = [];
        while (false !== ($file = $dir->read())) {
            if ('.' != $file[0] && substr($file, -4) == '.jpg') {
                $bgs[] = $path . $file;
            }
        }
        $dir->close();

        $gb = $bgs[array_rand($bgs)];

        list($width, $height) = @getimagesize($gb);
        // Resample
        $bgImage = @imagecreatefromjpeg($gb);
        @imagecopyresampled($this->im, $bgImage, 0, 0, 0, 0, (int)$this->imageW, (int)$this->imageH, $width, $height);
        @imagedestroy($bgImage);
    }
}
