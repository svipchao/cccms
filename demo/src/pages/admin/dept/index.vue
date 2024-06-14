<template>
  <Panel size="300px">
    <template #left>
      <div class="dept-button">
        <div>组织管理</div>
        <a-tooltip content="新增组织">
          <a-button
            type="text"
            @click="editData(nodeData)"
            v-permission="'admin/dept/create'"
          >
            <i class="ri-add-line"></i>
          </a-button>
        </a-tooltip>
      </div>
      <a-tree
        v-if="info.datas?.length > 0"
        blockNode
        showLine
        :data="info.datas"
        :fieldNames="{
          key: 'id',
          title: 'dept_name',
          children: 'children',
        }"
        :selected-keys="[deptStore.dept_id]"
        :default-expand-all="false"
        @select="selectDept"
      >
        <template #title="data">
          <a-typography-paragraph
            :ellipsis="{
              rows: 1,
              showTooltip: true,
            }"
          >
            {{ data.dept_name }}
          </a-typography-paragraph>
          <a-typography-paragraph
            :ellipsis="{
              rows: 1,
              showTooltip: true,
            }"
            style="font-size: 12px; color: #999"
          >
            {{ data.dept_desc }}
          </a-typography-paragraph>
        </template>
        <template #extra="data">
          <a-button-group>
            <a-tooltip content="修改组织">
              <a-button
                type="text"
                size="mini"
                @click="editData(data)"
                v-permission="'admin/dept/update'"
              >
                <template #icon>
                  <i
                    class="ri-edit-line"
                    style="color: rgb(var(--primary-6))"
                  ></i>
                </template>
              </a-button>
            </a-tooltip>
            <Popconfirm
              content="确定要删除吗？"
              type="warning"
              position="left"
              @ok="delData(data)"
            >
              <a-tooltip content="删除组织">
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
              </a-tooltip>
            </Popconfirm>
          </a-button-group>
        </template>
      </a-tree>
      <a-empty v-else />
      <DeptInfo
        v-model:visible="deptEditStatus.data.visible"
        :data="deptEditStatus.data.currentData"
        :depts="info.datas"
        :roles="info.roles"
        :nodes="info.nodes"
        @done="getDatas"
      />
    </template>
    <template #right>
      <UserList
        :hideCardBorder="true"
        :dept_id="deptStore.dept_id"
        :depts="deptStore.depts"
      />
    </template>
  </Panel>
</template>

<script setup>
import { reactive, onMounted } from 'vue';
import { Message } from '@arco-design/web-vue';
import { useDeptStore } from '@/store/admin/dept.js';
import { deptQuery, deptDelete } from '@/api/admin/dept.js';
import { useFormEdit } from '@/hooks/form.js';
import Popconfirm from '@/components/popconfirm/index.vue';
import Panel from '@/components/panel/index.vue';
import UserList from '../user/index.vue';
import DeptInfo from './info.vue';

const deptStore = useDeptStore();

onMounted(() => {
  getDatas();
});

const info = reactive({
  form: {
    user: undefined,
  },
  datas: [],
  roles: [],
  nodes: [],
});

const getDatas = async () => {
  const { data } = await deptQuery(info.form);
  info.datas = data.data;
  info.roles = data.roles;
  info.nodes = data.nodes;
  deptStore.setDepts(data.data);
};

let deptEditStatus = useFormEdit();

const editData = (row) => {
  deptEditStatus.updateFormEditStatus(row);
};

const delData = (row) => {
  deptDelete(row).then((res) => {
    Message.success('删除成功');
    getDatas();
  });
};

// tree选中取消方法
const selectDept = (selectedKeys) => {
  if (selectedKeys.indexOf(Number(deptStore.dept_id)) > -1) {
    deptStore.setDeptId(0);
  } else {
    deptStore.setDeptId(Number(selectedKeys));
  }
};
</script>

<style lang="less">
.cc-panel {
  .dept-button {
    padding-bottom: 10px;
    border-bottom: 1px solid var(--color-neutral-3);
    display: flex;
    div {
      width: 100%;
      text-align: center;
      line-height: 32px;
      margin-left: 46px;
      flex: 1 1 auto;
    }
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
