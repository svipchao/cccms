import { ref, reactive } from 'vue';
import { deepClone, assignObject } from '@/utils/utils.js';

export const useResetForm = (initialValues) => {
  const getFormInit = () => {
    // 深度复制
    return deepClone(initialValues);
  };
  const form = reactive(getFormInit());

  const setForm = (data) => {
    assignObject(form, data);
  };

  const resetForm = () => {
    Object.assign(form, getFormInit());
  };

  return { form, setForm, resetForm };
};

export function useFormEdit() {
  // 是否打开弹窗
  const showPopup = ref(false);

  // 当前编辑的数据
  const currentData = ref();

  const updateFormEditStatus = (row) => {
    showPopup.value = true;
    currentData.value = row;
  };

  return { showPopup, currentData, updateFormEditStatus };
}
