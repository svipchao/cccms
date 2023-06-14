<template>
  <div class="login">
    <div class="cccms-loader-main">
      <div class="cccms-loader"></div>
    </div>
    <a-form :model="userinfo" @finish="onFinish" @finishFailed="onFinishFailed">
      <a-form-item
        name="username"
        :rules="[{ required: true, message: '请输入账号！' }]"
      >
        <a-input
          size="large"
          placeholder="请输入账号！"
          v-model:value="userinfo.username"
        />
      </a-form-item>
      <a-form-item
        name="password"
        :rules="[{ required: true, message: '请输入密码！' }]"
      >
        <a-input-password
          size="large"
          placeholder="请输入密码！"
          v-model:value="userinfo.password"
        />
      </a-form-item>
      <a-form-item>
        <a-button type="primary" html-type="submit" size="large" block>
          登录
        </a-button>
      </a-form-item>
    </a-form>
  </div>
  <div className="footer-copyright">
    <span>&copy;{{ new Date().getFullYear() }}&nbsp;</span>
    <a :href="config.copyrightUrl" target="_blank">
      {{ config.copyrightTitle }}
    </a>
  </div>
</template>

<script setup>
import config from "@/config";
import router from "@/router";
import { reactive } from "vue";
import { login } from "@/api/admin/login.js";
import { useUser } from "@/store/admin/user.js";

const { setUserInfo } = useUser();

const userinfo = reactive({
  username: "",
  password: "",
});
const onFinish = (values) => {
  console.log("Success:", values);
};
const onFinishFailed = (errorInfo) => {
  console.log("Failed:", errorInfo);
};
</script>

<style scoped lang="less">
.login {
  display: grid;
  align-content: center;
  width: 100vw;
  height: calc(100vh - 50px);
  justify-content: center;
  .cccms-loader-main {
    width: 100% !important;
    height: 150px !important;
  }
  .ant-form {
    min-width: 320px;
    .ant-input:hover,
    .ant-input-password:hover,
    .ant-input:focus,
    .ant-input-password:focus,
    .ant-input-affix-wrapper:focus {
      border-color: #434755 !important;
      box-shadow: 0 0 0 2px rgb(0 0 0 / 10%) !important;
    }
    button {
      color: #fff;
      background-color: #20222a;
      &:hover {
        color: #fff;
        background-color: #434755 !important;
      }
    }
  }
}
.footer-copyright {
  height: 50px;
  line-height: 50px;
}
</style>
