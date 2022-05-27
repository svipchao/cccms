<template>
  <a-modal :visible="params.visible" :maskClosable="false" @cancel="cancelFun" @ok="okFun">
    <template #title>个人中心</template>
    <a-form :model="userInfoFrom" layout="vertical">
      <a-form-item field="nickname">
        <a-input v-model="userInfoFrom.nickname" placeholder="请输入昵称...">
          <template #prefix>昵称</template>
        </a-input>
      </a-form-item>
      <a-form-item field="username">
        <a-input v-model="userInfoFrom.username" placeholder="请输入用户名...">
          <template #prefix>用户名</template>
        </a-input>
      </a-form-item>
      <a-form-item field="password">
        <a-input v-model="userInfoFrom.password" placeholder="请输入密码，留空不修改...">
          <template #prefix>密码</template>
        </a-input>
      </a-form-item>
    </a-form>
  </a-modal>
</template>

<script setup>
import { reactive } from "vue";
import { Message } from "@arco-design/web-vue";
import { userUpdate } from "@/api/admin/user.js";
import { useUser } from "@/store/user.js";

const params = defineProps({
  visible: undefined,
});

const emit = defineEmits(["update:visible"]);

const userStore = useUser();

const userInfoFrom = reactive({
  id: userStore.id,
  nickname: userStore.nickname,
  username: userStore.username,
  password: undefined,
});

// 关闭弹窗
const cancelFun = () => {
  emit("update:visible", false);
};

// 修改个人信息
const okFun = () => {
  userUpdate(userInfoFrom).then((res) => {
    Message.success({
      content: "修改个人资料成功",
      onClose: () => {
        // 更新个人资料
        userStore.setUserInfo();
        emit("update:visible", false);
      },
    });
  });
};
</script>
