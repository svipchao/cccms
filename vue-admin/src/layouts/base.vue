<template>
  <a-layout>
    <a-layout-sider v-show="siderStore.siderState">
      <Sider />
    </a-layout-sider>
    <a-layout>
      <a-layout-header>
        <Header />
      </a-layout-header>
      <a-layout-content>
        <router-view />
      </a-layout-content>
    </a-layout>
    <div class="cc-mask" v-show="siderStore.siderState" @click="siderStore.changeSider()"></div>
  </a-layout>
</template>

<script setup>
import { onMounted } from "vue";
import Sider from "@/layouts/sider/index.vue";
import Header from "@/layouts/header/index.vue";
import { useSider } from "@/store/sider.js";
import { useUser } from "@/store/user.js";

const userStore = useUser();
const siderStore = useSider();
</script>
