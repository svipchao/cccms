<?php
declare (strict_types=1);

namespace cccms\traits;

trait Controller
{
    /**
     * 需要继承的方法
     * 控制器所继承的父类中有
     * index(列表显示)
     * create(新增显示)
     * edit(修改显示)
     * read(读取显示)
     */
    protected $noNeed = [];

    protected $pk = 'id'; // 主键

    protected $model;

    /**
     * 列表显示
     * @auth  true
     * @login true
     * @encode view
     * @methods GET
     */
    public function index()
    {
        _result([], _getEnCode('index'));
    }

    /**
     * 新增显示
     * @auth  true
     * @login true
     * @encode view
     * @methods GET
     */
    public function create()
    {
        _result([], _getEnCode('form'));
    }

    /**
     * 修改显示
     * @auth  true
     * @login true
     * @encode view
     * @methods GET
     */
    public function edit()
    {
        _result([], _getEnCode('form'));
    }

    /**
     * 读取显示
     * @auth  true
     * @login true
     * @encode view
     * @methods GET
     */
    public function read()
    {
        _result([], _getEnCode('read'));
    }
}