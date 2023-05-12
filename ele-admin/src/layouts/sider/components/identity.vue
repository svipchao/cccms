<template>
  <el-modal
    :mask-closable="false"
    :visible="visible"
    title="切换身份信息"
    @cancel="okModal"
    @ok="okModal"
  >
    <el-space>
      <el-select
        v-model="province"
        popup-visible
        :triggerProps="{
          popupVisible: true,
          popupStyle: {
            border: '1px solid red',
          },
        }"
      >
        <el-option v-for="value of Object.keys(data)">{{ value }}</el-option>
      </el-select>
      <el-select popup-visible :options="data[province] || []" v-model="city" />
    </el-space>
  </el-modal>
</template>
<script setup>
import { ref, reactive, watch } from "vue";
import { ElMessage } from "element-plus";
import { assignObject } from "@/utils/utils.js";

const props = defineProps({
  visible: false,
});

const province = ref("Sichuan");
const city = ref("Chengdu");
const data = {
  Beijing: ["Haidian", "Chaoyang", "Changping"],
  Sichuan: ["Chengdu", "Mianyang", "Aba"],
  Guangdong: ["Guangzhou", "Shenzhen", "Shantou"],
};
watch(province, () => {
  city.value = "";
});

const emit = defineEmits(["update:visible"]);

const okModal = async () => {
  emit("update:visible", false);
};
</script>
