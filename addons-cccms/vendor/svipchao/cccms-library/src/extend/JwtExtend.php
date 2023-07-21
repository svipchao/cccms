<?php
declare (strict_types=1);

namespace cccms\extend;

class JwtExtend
{
    // 头部
    private static $header = [
        'alg' => 'HS256', // 生成signature的算法
        'typ' => 'JWT'    // 类型
    ];

    /**
     * 获取jwt token
     * @param array $payload jwt载荷  格式如下非必须
     * [
     *     'iss' => 'jwt_admin',               // 该JWT的签发者
     *     'iat' => time(),                    // 签发时间
     *     'exp' => time() + 7200,             // 过期时间
     *     'nbf' => time() + 60,               // 该时间之前不接收处理该Token
     *     'sub' => 'www.cccms.cc',            // 面向的用户
     *     'jti' => md5(uniqid('JWT').time())  // 该Token唯一标识
     * ]
     * @return string
     */
    public static function getToken(array $payload): string
    {
        $base64header = self::base64UrlEncode(json_encode(self::$header, JSON_UNESCAPED_UNICODE));
        $base64payload = self::base64UrlEncode(json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $base64header . '.' . $base64payload . '.' . self::signature($base64header . '.' . $base64payload, config('base.jwtKey'), self::$header['alg']);
    }

    /**
     * base64UrlEncode https://jwt.io/ 中base64UrlEncode编码实现
     * @param string $input 需要编码的字符串
     * @return string
     */
    private static function base64UrlEncode(string $input): string
    {
        return str_replace('=', '', strtr(base64_encode($input), '+/', '-_'));
    }

    /**
     * HMACSHA256签名 https://jwt.io/ 中HMACSHA256签名实现
     * @param string $input 为base64UrlEncode(header).".".base64UrlEncode(payload)
     * @param string $key
     * @param string $alg 算法方式
     * @return string
     */
    private static function signature(string $input, string $key, string $alg = 'HS256'): string
    {
        $alg_config = ['HS256' => 'sha256'];
        return self::base64UrlEncode(hash_hmac($alg_config[$alg], $input, $key, true));
    }

    /**
     * 验证token是否有效,默认验证exp,nbf,iat时间
     * @param $token
     * @return bool|array
     */
    public static function verifyToken($token)
    {
        $tokens = explode('.', $token ?? '');
        if (count($tokens) != 3) {
            return false;
        }

        [$base64header, $base64payload, $sign] = $tokens;

        // 获取jwt算法
        $base64DecodeHeader = json_decode(self::base64UrlDecode($base64header), true);
        if (empty($base64DecodeHeader['alg'])) {
            return false;
        }

        // 签名验证
        if (self::signature($base64header . '.' . $base64payload, config('base.jwtKey'), $base64DecodeHeader['alg']) !== $sign) {
            return false;
        }

        $payload = json_decode(self::base64UrlDecode($base64payload), true);

        // 签发时间大于当前服务器时间验证失败
        if (isset($payload['iat']) && $payload['iat'] > time()) {
            return false;
        }

        // 过期时间小宇当前服务器时间验证失败
        if (isset($payload['exp']) && $payload['exp'] < time()) {
            return false;
        }

        // 该nbf时间之前不接收处理该Token
        if (isset($payload['nbf']) && $payload['nbf'] > time()) {
            return false;
        }
        return $payload;
    }

    /**
     * base64UrlEncode https://jwt.io/ 中base64UrlEncode解码实现
     * @param string $input 需要解码的字符串
     * @return bool|string
     */
    private static function base64UrlDecode(string $input)
    {
        $remainder = strlen($input) % 4;
        if ($remainder) {
            $addLen = 4 - $remainder;
            $input .= str_repeat('=', $addLen);
        }
        return base64_decode(strtr($input, '-_', '+/'));
    }
}

// iss (issuer)：签发人
// exp (expiration time)：过期时间
// sub (subject)：主题
// aud (audience)：受众
// nbf (Not Before)：生效时间
// iat (Issued At)：签发时间
// jti (JWT ID)：编号

// //测试和官网是否匹配begin
// $payload=array('sub'=>'1234567890','name'=>'John Doe','iat'=>1516239022);
// $jwt=new JwtLib;
// $token=$jwt->getToken($payload);
// echo "<pre>";
// echo $token;

// //对token进行验证签名
// $getPayload=$jwt->verifyToken($token);
// echo "<br><br>";
// var_dump($getPayload);
// echo "<br><br>";
// //测试和官网是否匹配end

// //自己使用测试begin
// $payload_test=array('iss'=>'admin','iat'=>time(),'exp'=>time()+7200,'nbf'=>time(),'sub'=>'www.admin.com','jti'=>md5(uniqid('JWT').time()));;
// $token_test=JwtLib::getToken($payload_test);
// echo "<pre>";
// echo $token_test;

// //对token进行验证签名
// $getPayload_test=JwtLib::verifyToken($token_test);
// echo "<br><br>";
// var_dump($getPayload_test);
// echo "<br><br>";
// //自己使用时候end