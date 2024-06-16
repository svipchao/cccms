<template>
  <a-card>
    <a-tabs
      :editable="false"
      hide-content
      show-add-button
      default-active-key="site"
      @change="switchConfigCate"
      style="padding-bottom: 10px"
    >
      <a-tab-pane
        v-for="(cate, index) in info.cate"
        :key="cate.cate_name"
        :title="cate.cate_desc"
      />
    </a-tabs>
    <a-row justify="center" :style="{ padding: '20px 0 30px 0' }">
      <a-col :xs="24" :sm="24" :md="14" :lg="12" :xl="10" :xxl="8">
        <DynamicForm
          ref="dynamicFormRef"
          :key="dynamicFormKey"
          v-model:data="info.data"
        />
        <a-button-group>
          <a-button @click="updateConfig" type="primary" long>提交</a-button>
          <a-button @click="createConfig" long v-if="false">
            新增配置项
          </a-button>
        </a-button-group>
      </a-col>
    </a-row>
  </a-card>
  <Info
    v-model:visible="configEditStatus.data.visible"
    :data="configEditStatus.data.currentData"
    :cate_name="info.cate_name"
    @done="getConfigs"
  />
</template>

<script setup>
import { reactive, ref, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import { configQuery, configUpdate } from '@/api/admin/config.js';
import { useUserStore } from '@/stores/admin/user.js';
import { useFormEdit } from '@/hooks/form.js';
import DynamicForm from '@/components/form/dynamic-form.vue';
import Info from './info.vue';

onMounted(() => {
  getConfigs();
});

const info = reactive({
  cate_name: 'site',
  cate: [],
  data: [],
});

let configEditStatus = useFormEdit();

const editMenu = (record) => {
  configEditStatus.updateFormEditStatus(record);
};

const getConfigs = async () => {
  const { data } = await configQuery({ cate_name: info.cate_name });
  info.cate = data.cate;
  info.data = data.data;
  // 重新渲染子组件
  dynamicFormKey.value++;
};

const dynamicFormRef = ref(null);
const dynamicFormKey = ref(1);

const updateConfig = () => {
  configUpdate(dynamicFormRef.value.getDynamicDatas()).then((res) => {
    Message.success('修改成功');
    useUserStore().setAccessToken();
  });
};

const createConfig = (row) => {
  Message.success('待完善');
};

const switchConfigCate = (key) => {
  info.cate_name = key;
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
