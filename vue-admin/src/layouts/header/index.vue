<template>
  <a-row>
    <a-col :flex="6">
      <a-space :size="5" align="center">
        <span class="arco-header-element" @click="siderStore.changeSider()">
          <i class="iconfont icon-appstore"></i>
        </span>
        <span class="arco-header-element">
          <router-link to="/index">
            <a-button :type="$route.path === '/index' ? 'outline' : 'dashed'">
              <i class="iconfont icon-dashboard"></i>
              <span>&nbsp;控制台</span>
            </a-button>
          </router-link>
        </span>
      </a-space>
    </a-col>
    <a-col :flex="6">
      <a-space :size="15">
        <div class="arco-header-element">
          <a-select
            :trigger-props="{
              autoFitPopupMinWidth: true,
              position: 'br',
              popupStyle: {
                top: '45px',
              },
            }"
          >
            <template #trigger>
              <span>
                <i class="iconfont icon-user"></i>
                <span>&nbsp;{{ userStore.nickname }}&nbsp;&nbsp;</span>
              </span>
            </template>
            <a-option @click="clearCache">
              <i class="iconfont icon-reload"></i>
              <span>&nbsp;清除缓存</span>
            </a-option>
            <a-option @click="userInfoModal = true">
              <i class="iconfont icon-user"></i>
              <span>&nbsp;个人中心</span>
            </a-option>
            <a-option @click="logout">
              <i class="iconfont icon-logout"></i>
              <span>&nbsp;注销登录</span>
            </a-option>
          </a-select>
        </div>
      </a-space>
    </a-col>
  </a-row>
  <UserInfo v-model:visible="userInfoModal" />
</template>

<script setup>
import { ref } from "vue";
import { Message } from "@arco-design/web-vue";
import UserInfo from "./userinfo.vue";
import { useSider } from "@/store/sider.js";
import { useUser } from "@/store/user.js";

const siderStore = useSider();
const userStore = useUser();

// 个人中心 未选择角色则开启个人中心弹窗
const userInfoModal = ref(userStore.role_id === 0);

const clearCache = () => {
  userStore.setUserInfo();
  Message.success("清除缓存成功");
};

const logout = () => {
  Message.success({
    content: "注销成功",
    onClose: function () {
      userStore.logout();
    },
  });
};
</script>
