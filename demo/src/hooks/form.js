import { ref, reactive, toRaw } from 'vue';
// import { useCloned } from '@vueuse/core';
import { assignObject } from '@/utils/utils.js';

export const useResetForm = (initialValues) => {
  let form = ref(initialValues);

  const getFormInit = () => {
    const oldData = reactive({ ...initialValues });
    return toRaw(oldData);
  };

  const setForm = (data) => {
    Object.assign(form, data);
  };

  const resetForm = () => {
    // Object.assign(form, getFormInit());
    // form = getFormInit();
    // console.log(getFormInit());
    // console.log(form);
    // console.log('==================');
    form.value = initialValues;
    // form = getFormInit();
  };
  return { form, setForm, resetForm };
  // 这里有可能修改角色Nodes有问题 上方代码暂且保留
  // const form = reactive({ ...initialValues });

  // const isUpdate = ref(false);

  // const resetForm = () => {
  //   Object.keys(form).forEach((key) => {
  //     form[key] = initialValues ? initialValues[key] : void 0;
  //   });
  // };

  // const setForm = (data) => {
  //   if (typeof data !== 'undefined') {
  //     Object.keys(form).forEach((key) => {
  //       form[key] = data[key];
  //     });
  //     isUpdate.value = true;
  //   } else {
  //     isUpdate.value = false;
  //   }
  // };

  // return {
  //   form,
  //   isUpdate,
  //   // 重置为初始值
  //   resetForm,
  //   // 赋值不改变字段
  //   setForm,
  // };
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
