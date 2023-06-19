<template>
  <div class="login">
    <div class="login-body">
      <h1>用户登录</h1>
      <a-form
        :model="userinfo"
        @finish="onFinish"
        @finishFailed="onFinishFailed"
      >
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
            autocomplete
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
  width: 100vw;
  align-content: center;
  height: calc(100vh - 50px);
  justify-content: center;
  overflow: hidden;
  .login-body {
    width: 400px;
    padding: 25px;
    overflow: hidden;
    border-radius: 5px;
    text-align: center;
    background-color: #fff;
    box-shadow: 0 6px 16px 0 rgba(0, 0, 0, 0.08);
    @media screen and (max-width: 760px) {
      width: 320px;
      box-shadow: none;
    }
    h1 {
      font-size: 24px;
      padding: 0 0 20px 0;
    }
  }
  .ant-form {
    .ant-form-item:last-child {
      margin-bottom: 0px;
    }
  }
}
.footer-copyright {
  height: 50px;
  line-height: 50px;
}
</style>
