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
        <a-tabs class="login-tabs" type="capsule" @change="switchTags">
          <a-tab-pane key="login" title="密码登录">
            <a-form
              ref="formRef"
              :model="loginUserinfo"
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
                  v-model="loginUserinfo.username"
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
                  v-model="loginUserinfo.password"
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
                    minLength: 1,
                    maxLength: 5,
                    message: '密码长度不符合要求1,5',
                  },
                ]"
                v-if="isOpenCaptcha"
              >
                <a-space :size="10">
                  <a-input
                    placeholder="请输入验证码"
                    v-model="loginUserinfo.captcha"
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
                <a-button type="primary" html-type="submit" long>
                  立即登录
                </a-button>
              </a-form-item>
            </a-form>
          </a-tab-pane>
          <a-tab-pane key="register" title="立即注册" disabled>
            <a-form
              ref="formRef"
              :model="registerUserinfo"
              layout="vertical"
              size="large"
              @submit-success="doRegister"
            >
              <a-form-item
                feedback
                hide-asterisk
                field="username"
                :rules="[
                  { required: true, message: '请输入账号！' },
                  {
                    minLength: 5,
                    maxLength: 25,
                    message: '账号长度不符合要求5,25',
                  },
                ]"
              >
                <a-input
                  placeholder="请输入注册账号"
                  v-model="registerUserinfo.username"
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
                  { required: true, message: '请输入密码！' },
                  {
                    minLength: 5,
                    maxLength: 25,
                    message: '密码长度不符合要求5,25',
                  },
                ]"
              >
                <a-input-password
                  placeholder="请输入注册密码"
                  v-model="registerUserinfo.password"
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
                    minLength: 1,
                    maxLength: 5,
                    message: '验证码长度不符合要求4,5',
                  },
                ]"
                v-if="isOpenCaptcha"
              >
                <a-space :size="10">
                  <a-input
                    placeholder="请输入验证码"
                    v-model="registerUserinfo.captcha"
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
                <a-button type="primary" html-type="submit" long>
                  立即注册
                </a-button>
              </a-form-item>
            </a-form>
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
    {{ demoForm.form }}
    <a-input
      placeholder="请输入注册账号"
      v-model="demoForm.form.dept_name"
    ></a-input>
    <a-button @click="demo">重置</a-button>
  </div>
</template>

<script setup>
import config from '@/config';
import { onMounted, reactive, ref } from 'vue';
import { Message } from '@arco-design/web-vue';
import { login, register, getCaptcha } from '@/api/admin/user.js';
import { useUserStore } from '@/stores/admin/user.js';
import { useResetForm } from '@/hooks/form.js';

const demoForm = useResetForm({
  id: undefined,
  dept_id: undefined,
  dept_name: '',
  nodes: [
    {
      name: 'admin',
      son: [
        {
          name: 'admin1',
          son: [],
        },
      ],
    },
    {
      name: 'admin',
      son: [
        {
          name: 'admin1',
          son: [],
        },
      ],
    },
    {
      name: 'admin',
      son: [
        {
          name: 'admin1',
          son: [],
        },
      ],
    },
  ],
});

const demo = () => {
  demoForm.resetForm();
};

const { setUserInfo } = useUserStore();

const loginUserinfo = reactive({
  username: '',
  password: '',
  captcha: '',
  captchaToken: '',
});

const isOpenCaptcha = ref(true);

const captcha = ref(
  'data:image/gif;base64,R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7'
);

onMounted(() => {
  getCaptchaFun('login');
});

const switchTags = (key) => {
  getCaptchaFun(key);
};

const getCaptchaFun = async (key) => {
  let node = '';
  if (key == 'login') {
    node = 'admin/login/index';
  } else {
    node = 'admin/login/register';
  }
  const { data } = await getCaptcha({ node: node });
  captcha.value = data.base64;
  isOpenCaptcha.value = data.open;
  if (key == 'login') {
    loginUserinfo.captchaToken = data.captchaToken;
  } else {
    registerUserinfo.captchaToken = data.captchaToken;
  }
};

const doLogin = () => {
  login(loginUserinfo).then((res) => {
    if (res.code == 200) {
      setUserInfo(res.data);
    } else {
      Message.error('验证码错误');
      getCaptchaFun('login');
    }
  });
};

const registerUserinfo = reactive({
  username: '',
  password: '',
  captcha: '',
  captchaToken: '',
});

const doRegister = () => {
  register(registerUserinfo).then((res) => {
    if (res.code == 200) {
      Message.success('注册成功');
    } else {
      Message.error('验证码错误');
      getCaptchaFun('register');
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
  background-image: url('@/assets/login/bg.svg');
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
