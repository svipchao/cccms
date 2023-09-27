<template>
  <div class="login">
    <a-row class="login-body">
      <a-col flex="520px" class="login-left">
        <div class="login-copyright">
          <h1>{{ config.title }}</h1>
          <span>{{ config.description }}</span>
        </div>
        <a-carousel
          auto-play
          indicator-type="line"
          animation-name="fade"
          class="login-banner"
        >
          <a-carousel-item>
            <img src="@/assets/login/banner-1.png" />
          </a-carousel-item>
          <a-carousel-item>
            <img src="@/assets/login/banner-2.png" />
          </a-carousel-item>
          <a-carousel-item>
            <img src="@/assets/login/banner-3.png" />
          </a-carousel-item>
        </a-carousel>
      </a-col>
      <a-col flex="auto" class="login-right">
        <a-tabs class="login-tabs" type="capsule">
          <a-tab-pane key="1" title="密码登录">
            <a-form
              ref="formRef"
              :model="userinfo"
              layout="vertical"
              size="large"
              @submit-success="doLogin"
            >
              <a-form-item
                feedback
                hide-asterisk
                field="username"
                :rules="[
                  { required: true, message: '请输入登录账号！' },
                  {
                    minLength: 5,
                    maxLength: 25,
                    message: '账号长度不符合要求5,25',
                  },
                ]"
              >
                <a-input
                  placeholder="请输入登录账号"
                  v-model="userinfo.username"
                >
                  <template #prefix>
                    <i class="ri-user-3-line"></i>
                  </template>
                </a-input>
              </a-form-item>
              <a-form-item
                feedback
                hide-asterisk
                field="password"
                :rules="[
                  { required: true, message: '请输入登录密码！' },
                  {
                    minLength: 5,
                    maxLength: 25,
                    message: '密码长度不符合要求5,25',
                  },
                ]"
              >
                <a-input-password
                  placeholder="请输入登录密码"
                  v-model="userinfo.password"
                >
                  <template #prefix>
                    <i class="ri-lock-line"></i>
                  </template>
                </a-input-password>
              </a-form-item>
              <a-form-item
                feedback
                hide-asterisk
                field="captcha"
                :rules="[
                  { required: true, message: '请输入验证码！' },
                  {
                    minLength: 4,
                    maxLength: 5,
                    message: '密码长度不符合要求4,5',
                  },
                ]"
                v-if="isOpenCaptcha"
              >
                <a-space :size="10">
                  <a-input
                    placeholder="请输入验证码"
                    v-model="userinfo.captcha"
                  >
                    <template #prefix>
                      <i class="ri-shield-check-line"></i>
                    </template>
                  </a-input>
                  <img
                    :src="captcha"
                    class="login-captcha"
                    @click="getCaptchaFun()"
                  />
                </a-space>
              </a-form-item>
              <a-form-item>
                <a-button type="primary" html-type="submit" long>登录</a-button>
              </a-form-item>
            </a-form>
          </a-tab-pane>
          <a-tab-pane key="3" title="手机登录" disabled>
            <a-empty />
          </a-tab-pane>
        </a-tabs>
        <div class="login-copyright-link">
          <span>&copy;{{ new Date().getFullYear() }}&nbsp;</span>
          <a :href="config.copyrightUrl" target="_blank">
            {{ config.copyrightTitle }}
          </a>
        </div>
      </a-col>
    </a-row>
  </div>
</template>

<script setup>
import config from "@/config";
import router from "@/router";
import { onMounted, reactive, ref } from "vue";
import { Message } from "@arco-design/web-vue";
import { login, getCaptcha } from "@/api/admin/login.js";
import { useUser } from "@/store/admin/user.js";

const { setUserInfo } = useUser();

const userinfo = reactive({
  username: "admin",
  password: "admin",
  captcha: "admin",
  captchaToken: "admin",
});

const isOpenCaptcha = ref(true);

const captcha = ref(
  "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
);

onMounted(() => {
  getCaptchaFun();
});

const getCaptchaFun = async () => {
  const { data } = await getCaptcha();
  captcha.value = data.base64;
  isOpenCaptcha.value = data.open;
  userinfo.captchaToken = data.captchaToken;
};

const doLogin = () => {
  login(userinfo).then((res) => {
    if (res.code == 200) {
      setUserInfo(res.data);
    } else {
      Message.error("验证码错误");
      getCaptchaFun();
    }
  });
};
</script>

<style lang="less">
.login {
  width: 100vw;
  height: 100vh;
  display: grid;
  align-content: center;
  justify-content: center;
  background-size: 100%;
  background-position: center 100px;
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
    @media screen and (max-width: 930px) {
      max-width: 400px;
      box-shadow: none;
    }
    .login-left {
      width: 520px;
      height: 460px;
      background: #1681fd;
      @media screen and (max-width: 930px) {
        display: none;
      }
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
        width: 100%;
        height: 350px;
        margin: 0 auto;
        img {
          width: 100%;
          height: 100%;
        }
      }
    }
    .login-right {
      width: 400px;
      padding: 40px;
      .login-tabs {
        height: 355px;
        .arco-tabs-nav-tab {
          justify-content: center;
        }
        .arco-tabs-content {
          padding: 30px 0px 10px 0px;
          .arco-input-wrapper {
            height: 40px !important;
          }
          .login-captcha {
            cursor: pointer;
            width: 110px;
            height: 40px;
          }
        }
      }
      .login-copyright-link {
        color: #999;
        height: 25px;
        line-height: 25px;
        text-align: center;
        a {
          color: #999;
          text-decoration: none;
        }
      }
    }
  }
}
</style>
