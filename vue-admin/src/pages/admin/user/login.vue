<template>
  <a-row>
    <a-col :lg="15" class="login-left">
      <h1>{{ config.title }}</h1>
      <span>{{ config.description }}</span>
    </a-col>
    <a-col :lg="9" class="login-right">
      <div class="login-loader-main">
        <div class="login-loader"></div>
      </div>
      <a-form
        ref="formRef"
        :model="state"
        layout="vertical"
        @submit-success="handleSubmit"
        size="large"
      >
        <a-form-item
          hide-asterisk
          field="username"
          :rules="[
            { required: true, message: '账号不能为空' },
            { minLength: 5, maxLength: 25, message: '账号长度不符合要求5,25' },
          ]"
        >
          <a-input v-model="state.username" placeholder="账号">
            <template #prefix>账号</template>
          </a-input>
        </a-form-item>
        <a-form-item
          hide-asterisk
          field="password"
          :rules="[
            { required: true, message: '密码不能为空' },
            { minLength: 5, maxLength: 25, message: '密码长度不符合要求5,25' },
          ]"
        >
          <a-input type="password" v-model="state.password" placeholder="密码">
            <template #prefix>密码</template>
          </a-input>
        </a-form-item>
        <a-form-item class="login-button">
          <a-button html-type="submit" long>提交</a-button>
        </a-form-item>
      </a-form>
    </a-col>
  </a-row>
</template>

<script setup>
import config from "@/config";
import { reactive } from "vue";
import { Message } from "@arco-design/web-vue";
import router from "@/router";
import { useUser } from "@/store/user.js";

let state = reactive({
  username: undefined,
  password: undefined,
});

function handleSubmit(values) {
  const { setUserInfo } = useUser();
  setUserInfo(values, { accessToken: undefined }).then((res) => {
    Message.success({
      content: "登录成功",
      onClose: () => {
        router.push("/");
      },
    });
  });
}
</script>
