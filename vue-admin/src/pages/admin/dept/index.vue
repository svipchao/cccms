<template>
  <Panel size="300px">
    <template #left>
      <div class="dept-button">
        <a-button type="primary" long @click="editData()">添加</a-button>
      </div>
      <a-tree
        v-if="deptInfo.datas?.length > 0"
        blockNode
        showLine
        :data="deptInfo.datas"
        :fieldNames="{
          key: 'id',
          title: 'dept_name',
          children: 'children',
        }"
        :selected-keys="[deptInfo.currentSelectDeptId]"
        :default-expand-all="false"
        @select="selectDept"
      >
        <template #title="node">
          <a-typography-paragraph
            :ellipsis="{
              rows: 1,
              showTooltip: true,
            }"
          >
            {{ node.dept_name }}
          </a-typography-paragraph>
          <a-typography-paragraph
            :ellipsis="{
              rows: 1,
              showTooltip: true,
            }"
            style="font-size: 12px; color: #999"
          >
            {{ node.dept_desc }}
          </a-typography-paragraph>
        </template>
        <template #extra="nodeData">
          <a-button-group>
            <a-button
              type="text"
              size="mini"
              @click="editData(nodeData)"
              v-permission="'admin/dept/update'"
            >
              <template #icon>
                <i class="ri-edit-line"></i>
              </template>
            </a-button>
            <Popconfirm
              content="确定要删除吗？"
              type="warning"
              position="left"
              @ok="delData(nodeData)"
            >
              <a-button
                type="text"
                size="mini"
                v-permission="'admin/dept/delete'"
              >
                <template #icon>
                  <i
                    class="ri-delete-bin-line"
                    style="color: rgb(var(--danger-6))"
                  ></i>
                </template>
              </a-button>
            </Popconfirm>
          </a-button-group>
        </template>
      </a-tree>
      <a-empty v-else />
      <DeptInfo
        v-model:visible="showPopup"
        :data="currentData"
        :depts="deptInfo.datas"
        @done="getDatas"
      />
    </template>
    <template #right>
      <UserList
        :dept="deptInfo.datas"
        :currentSelectDeptId="deptInfo.currentSelectDeptId"
      />
    </template>
  </Panel>
</template>

<script setup>
import { ref, reactive, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import Header from '@/components/table/header.vue';
import Table from '@/components/table/index.vue';
import Popconfirm from '@/components/popconfirm/index.vue';
import DeptInfo from './components/info.vue';
import UserList from './user/index.vue';
import Panel from '@/components/panel/index.vue';
import { deptQuery, deptUpdate, deptDelete } from '@/api/admin/dept.js';
import { useFormEdit } from '@/hooks/form.js';
import { detectDeviceType } from '@/utils/browser.js';

onMounted(() => {
  getDatas();
});

const deptInfo = reactive({
  form: {
    user: undefined,
  },
  datas: [],
  currentSelectDeptId: 0,
});

const getDatas = async () => {
  const { data } = await deptQuery({
    ...deptInfo.form,
  });
  deptInfo.datas = data.data;
};

// 切换状态
const changeStatusFun = (record) => {
  deptUpdate({ id: record.id, status: record.status }).then((res) => {
    Message.success('更新成功');
  });
};

const { showPopup, currentData, updateFormEditStatus } = useFormEdit();

const editData = (row) => {
  updateFormEditStatus(row);
};

const delData = (row) => {
  deptDelete(row).then((res) => {
    Message.success('删除成功');
    getDatas();
  });
};

// tree选中取消方法
const selectDept = (selectedKeys) => {
  if (selectedKeys.indexOf(Number(deptInfo.currentSelectDeptId)) > -1) {
    deptInfo.currentSelectDeptId = 0;
  } else {
    deptInfo.currentSelectDeptId = Number(selectedKeys);
  }
};

const paneSplitSize = ref('300px');
const demoFun = () => {
  paneSplitSize.value = paneSplitSize.value === '300px' ? '0px' : '300px';
};
</script>
<style lang="less">
.cc-panel {
  .dept-button {
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-neutral-3);
  }
  .arco-tree {
    height: calc(100vh - 173px);
    overflow: hidden;
    overflow-y: auto;
    &::-webkit-scrollbar {
      width: 0;
      height: 0;
    }
    .arco-typography {
      margin-bottom: 0;
    }
    .arco-tree-node-title {
      display: block;
    }
    .arco-tree-node-selected .arco-typography {
      color: rgb(var(--primary-6)) !important;
      transition: color 0.2s cubic-bezier(0, 0, 1, 1);
    }
  }
}
</style>
