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
        <a-tabs centered class="login-tabs">
          <a-tab-pane key="1" tab="密码登录">
            <a-form
              :model="userinfo"
              name="userinfo"
              autocomplete="off"
              @finish="setUserInfo"
            >
              <a-form-item
                name="username"
                :rules="[{ required: true, message: '请输入登录账号！' }]"
              >
                <a-input
                  placeholder="请输入登录账号"
                  size="large"
                  v-model="userinfo.username"
                >
                  <template #prefix>
                    <i class="ri-user-3-line"></i>
                  </template>
                </a-input>
              </a-form-item>
              <a-form-item
                name="password"
                :rules="[{ required: true, message: '请输入登录密码！' }]"
              >
                <a-input-password
                  placeholder="请输入登录密码"
                  size="large"
                  v-model="userinfo.password"
                >
                  <template #prefix>
                    <i class="ri-lock-line"></i>
                  </template>
                </a-input-password>
              </a-form-item>
              <a-form-item
                name="captcha"
                :rules="[{ required: true, message: '请输入验证码！' }]"
              >
                <a-space :size="10">
                  <a-input
                    placeholder="请输入验证码"
                    size="large"
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
                <a-button type="primary" block size="large" html-type="submit">
                  登录
                </a-button>
              </a-form-item>
            </a-form>
          </a-tab-pane>
          <a-tab-pane key="3" tab="手机登录">
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
import { login, getCaptcha } from "@/api/admin/login.js";
import { useUser } from "@/store/admin/user.js";

const { setUserInfo } = useUser();

const userinfo = reactive({
  username: "",
  password: "",
  captcha: "",
  captchaToken: "",
});

const captcha = ref(
  "data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7"
);

onMounted(() => {
  getCaptchaFun();
});

const getCaptchaFun = async () => {
  const { data } = await getCaptcha();
  captcha.value = data.base64;
  userinfo.captchaToken = data.captchaToken;
};
</script>

<style scoped lang="less">
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
    // overflow: hidden;
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
        width: 500px;
        height: 350px;
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
        height: 356px;
        .ant-tabs-tabpane {
          padding: 20px 0px;
          .ant-input-affix-wrapper {
            i {
              color: #aaa;
            }
          }
          .login-captcha {
            cursor: pointer;
            width: 85px;
            height: 40px;
            border-radius: 8px;
            border: 1px solid #d9d9d9;
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
        }
      }
    }
  }
}
</style>
