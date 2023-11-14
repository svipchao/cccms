<template>
  <a-card>
    <a-tabs
      default-active-key="site"
      @change="switchConfigCate"
      hide-content
      editable
      show-add-button
      style="padding-bottom: 10px"
    >
      <a-tab-pane
        v-for="(cate, index) in configInfo.cates"
        :key="cate.config_name"
        :title="cate.config_desc"
      />
    </a-tabs>
    <a-row justify="center" :style="{ padding: '20px 0 30px 0' }">
      <a-col :xs="24" :sm="24" :md="14" :lg="12" :xl="10" :xxl="8">
        <dynamic-form
          ref="dynamicFormRef"
          :key="dynamicFormKey"
          v-model:data="configInfo.datas"
        />
        <a-button-group>
          <a-button @click="updateConfig" type="primary" long>提交</a-button>
          <a-button @click="createConfig" long>新增配置项</a-button>
        </a-button-group>
      </a-col>
    </a-row>
  </a-card>
  <config-edit
    v-model:visible="showEdit"
    :data="currentData"
    :type_id="configInfo.type_id"
    @done="getConfigs"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import { configQuery, configDelete, configUpdate } from '@/api/admin/config';
import Types from '@/components/types/index.vue';
import DynamicForm from '@/components/form/dynamic-form.vue';
import ConfigEdit from './config-edit.vue';
import { useUser } from '@/store/admin/user.js';

const userStore = useUser();

onMounted(() => {
  getConfigs();
});

const configInfo = reactive({
  config_name: 'site',
  cates: [],
  datas: [],
});

// 是否打开弹窗
const showEdit = ref(false);

// 当前编辑的数据
const currentData = ref();

const getConfigs = async () => {
  const {
    data: { data, cates },
  } = await configQuery({ config_name: configInfo.config_name });
  configInfo.cates = cates;
  configInfo.datas = data;
  // 重新渲染子组件
  dynamicFormKey.value++;
};

const dynamicFormRef = ref(null);
const dynamicFormKey = ref(1);

const updateConfig = () => {
  configUpdate(dynamicFormRef.value.getDynamicDatas()).then((res) => {
    Message.success('修改成功');
    userStore.setAccessToken();
  });
};

const createConfig = (row) => {
  Message.success('待完善');
};

const switchConfigCate = (key) => {
  configInfo.config_name = key;
  getConfigs();
};
/**
type
input 输入框
{
    "name":"siteIcp",
    "label":"备案号",
    "type" : "input",
    "placeholder" : "请输入网站备案号",
    "default" : "豫ICP备93093369号",
    "description" : "请输入网站备案号"
}
input-number 数字输入框
{
    "name":"uploadSize",
    "label":"上传文件限制",
    "type" : "input-number",
    "placeholder" : "请输入上传文件限制",
    "default" : "20",
    "description" : "请输入上传文件限制(MB)"
}
textarea 文本域
{
    "name":"uploadExt",
    "label":"上传文件类型",
    "type" : "textarea",
    "placeholder" : "请输入上传文件支持的后缀名",
    "default" : "page,limit",
    "description" : "每个后缀名使用英文逗号隔开，例如：jpg,png,gif"
}
select 下拉框
{
    "name":"diskType",
    "label":"磁盘类型",
    "type":"select",
    "placeholder":"请选择磁盘类型",
    "default":"local",
    "description":"请选择磁盘类型",
    "options":[
        {
            "value":"local",
            "label":"本地"
        },
    ]
}
multiple-select 多选下拉框
{
    "name":"logMethods",
    "label":"监控类型",
    "type":"multiple-select",
    "placeholder":"请选择需要监控的类型",
    "default":["POST","PUT","DELETE"],
    "description":"参考：https://www.runoob.com/http/http-methods.html",
    "options":[
        {
            "value":"GET",
            "label":"GET"
        },
    ]
}
date-picker 日期选择器
{
    "name":"site_name",
    "label":"日期选择器",
    "type":"date-picker",
    "default":"",
    "description":"网站描述",
}
date-range-picker 日期范围选择器
{
    "name":"site_name",
    "label":"时间范围选择器",
    "type":"date-range-picker",
    "default":{
        "start":"",
        "end":""
    },
    "description":"网站描述",
}
switch 开关
{
    "name":"logClose",
    "label":"日志状态",
    "type":"switch",
    "default":1,
    "description":"日志状态",
    "options":{
        "checked":1,
        "unchecked":0
    }
}
*/
</script>
