<?php
declare(strict_types=1);

namespace app\index\controller;

use cccms\{Base, Storage};

/**
 * 附件
 * @sort 999
 */
class File extends Base
{
    /**
     * 附件
     * @auth false
     * @login false
     * @encode json|jsonp|xml|view
     * @methods GET
     */
    public function file()
    {
        Storage::instance()->query();
    }
}