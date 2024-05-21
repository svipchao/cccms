import { useUserStore } from '@/store/admin/user.js';

const permission = {
  mounted(el, binding) {
    let permission = binding.value;
    if (permission) {
      const { nodes } = useUserStore();
      if (!(nodes.indexOf(permission) > -1)) {
        // 没有权限 移除Dom元素
        el.parentNode && el.parentNode.removeChild(el);
      }
    }
  },
};
export default permission;
