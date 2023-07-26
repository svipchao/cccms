<?php
declare(strict_types=1);

namespace cccms\model;

use cccms\Model;

class SysPost extends Model
{
    public function getAllOpenPostIds(): array
    {
        return $this->where('status', 1)->cache('allPostOpenId')->column('id');
    }
}
