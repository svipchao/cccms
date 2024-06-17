import { ref, reactive, toRefs } from 'vue';
// import { useCloned } from '@vueuse/core';
import { deepClone } from '@/utils/utils.js';
import { useCloned } from '@vueuse/core';

export const useResetForm = (initialValues) => {
  const getFormInit = () => {
    return deepClone(initialValues);
  };

  const form = reactive(getFormInit());

  const isUpdate = ref(false);

  const resetForm = () => {
    initialValues = getFormInit();
    Object.keys(form).forEach((key) => {
      form[key] = initialValues ? initialValues[key] : undefined;
    });
  };

  const setForm = (data) => {
    if (typeof data !== 'undefined') {
      Object.keys(form).forEach((key) => {
        form[key] = data[key];
      });
      isUpdate.value = true;
    } else {
      isUpdate.value = false;
    }
  };

  return {
    form,
    isUpdate,
    // 重置为初始值
    resetForm,
    // 赋值不改变字段
    setForm,
  };
};

export const useFormEdit = () => {
  const data = reactive({
    // 是否打开弹窗
    visible: false,
    // 当前编辑的数据
    currentData: {},
  });
  const updateFormEditStatus = (row) => {
    data.visible = true;
    data.currentData = row;
  };
  return { data, updateFormEditStatus };
};
