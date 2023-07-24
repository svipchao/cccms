<template>
  <div class="login">
    <a-row class="login-body">
      <a-col flex="520px" class="login-left">
        <div class="login-copyright">
          <h1>{{ config.title }}</h1>
          <span>{{ config.description }}</span>
        </div>
        <a-carousel autoplay dot-position="bottom" class="login-banner">
          <img src="@/assets/login/banner-1.png" />
          <img src="@/assets/login/banner-2.png" />
          <img src="@/assets/login/banner-3.png" />
        </a-carousel>
      </a-col>
      <a-col flex="auto" class="login-right">
        <a-tabs centered>
          <a-tab-pane key="1" tab="密码登录">Content of Tab Pane 1</a-tab-pane>
          <a-tab-pane key="3" tab="手机登录">Content of Tab Pane 3</a-tab-pane>
        </a-tabs>
      </a-col>
    </a-row>
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
</script>

<style scoped lang="less">
.login {
  width: 100vw;
  height: 100vh;
  display: grid;
  align-content: center;
  justify-content: center;
  background-size: 100% 100%;
  background-repeat: no-repeat;
  background-image: url("@/assets/login/bg.svg");
  .login-body {
    width: 920px;
    height: 460px;
    border-radius: 8px;
    overflow: hidden;
    background-color: #fff;
    box-shadow: 0 6px 16px 0 rgba(0, 0, 0, 0.08),
      0 3px 6px -4px rgba(0, 0, 0, 0.12), 0 9px 28px 8px rgba(0, 0, 0, 0.05);
    .login-left {
      width: 520px;
      height: 460px;
      background: #1681fd;
      .login-copyright {
        height: 110px;
        text-align: center;
        padding: 25px;
        h1 {
          color: #fff;
          font-size: 26px;
          font-weight: 400;
          letter-spacing: 1.5px;
        }
        span {
          color: #f5f5f5;
          font-size: 16px;
          letter-spacing: 4px;
        }
      }
      .login-banner {
        width: 500px;
        height: 350px;
        img {
          width: 100%;
          height: 100%;
        }
      }
    }
    .login-right {
      padding: 15px 35px;
    }
  }
}
</style>
