<template>
  <div class="login">
    <div class="cccms-loader-main">
      <div class="cccms-loader"></div>
    </div>
    <a-form
      ref="formRef"
      :model="userinfo"
      layout="vertical"
      @submit-success="setUserInfo"
      size="large"
    >
      <a-form-item
        feedback
        hide-asterisk
        field="username"
        :rules="[
          { required: true, message: '账号不能为空' },
          { minLength: 5, maxLength: 25, message: '账号长度不符合要求5,25' },
        ]"
      >
        <a-input v-model="userinfo.username" placeholder="账号">
          <template #prefix>账号</template>
        </a-input>
      </a-form-item>
      <a-form-item
        feedback
        hide-asterisk
        field="password"
        :rules="[
          { required: true, message: '密码不能为空' },
          { minLength: 5, maxLength: 25, message: '密码长度不符合要求5,25' },
        ]"
      >
        <a-input type="password" v-model="userinfo.password" placeholder="密码">
          <template #prefix>密码</template>
        </a-input>
      </a-form-item>
      <a-form-item class="login-button">
        <a-button html-type="submit" long>登录</a-button>
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
import { ElMessage } from "element-plus";
import { useUser } from "@/store/admin/user.js";

const { setUserInfo } = useUser();

let userinfo = reactive({
  username: undefined,
  password: undefined,
});
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
    height: 100px !important;
  }
  .arco-form {
    min-width: 320px;
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
