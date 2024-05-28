import { createRouter, createWebHashHistory } from 'vue-router';
import { useTabsStore } from '@/stores/admin/tabs.js';
import { useMenuStore } from '@/stores/admin/menu.js';
import { useUserStore } from '@/stores/admin/user.js';
import { expandArray } from '@/utils/array.js';
import NProgress from 'nprogress';
import config from '@/config';

const router = createRouter({
  routes: [
    {
      name: 'default',
      path: '/',
      redirect: '/admin/index/index',
      component: () => import('@/layouts/default/index.vue'),
      children: [
        {
          path: '/:pathMatch(.*)*',
          component: () => import('@/components/result/404.vue'),
          meta: { title: '404' },
        },
      ],
    },
    {
      name: 'login',
      path: '/login',
      component: () => import('@/pages/admin/user/login.vue'),
      meta: { title: '登录' },
    },
  ],
  history: createWebHashHistory(),
});

let modules = import.meta.glob('../pages/**/*.vue');

// 路由守卫
router.beforeEach(async (to, from, next) => {
  NProgress.start();
  // 判断是否需要登录
  const userStore = useUserStore();
  if (!userStore.accessToken) {
    if (to.name == 'login') {
      next();
    } else {
      // 未登录 跳转登录页
      next({ path: '/login' });
    }
  } else {
    // 解决刷新页面路由不生效问题
    if (userStore.isRegisterRouteFresh) {
      const menuStore = useMenuStore();
      const menus = expandArray(menuStore.menus || []);
      menus.forEach((item) => {
        if (item.node !== '#') {
          router.addRoute(item.layout_name, {
            name: item.node,
            path: '/' + item.url,
            meta: { id: item.id, icon: item.icon, title: item.name },
            component: modules[`../pages/${item.url}.vue`],
          });
          // router.addRoute('layouts', {
          //   name: item.node,
          //   path: '/' + item.url,
          //   meta: { id: item.id, icon: item.icon, title: item.name },
          //   component: async () => {
          //     let cpn = await import(
          //       /* @vite-ignore */ '../pages/' + item.node + '.vue'
          //     );
          //     cpn.default.name = item.node;
          //     return cpn;
          //   },
          // });
        }
      });
      next({ ...to, replace: true });
      userStore.setRegisterRouteFresh();
    } else {
      // keep-alive 实现
      const tabsStore = useTabsStore();
      const tab = tabsStore.getCacheTab(to.name);
      if (!tab && to.name) {
        tabsStore.cacheTabs.push(to.name);
      }
      next();
    }
  }
  // 更新浏览器标题
  document.title = (to.meta.title || '未命名') + ' - ' + config.title;
  // 解决部分页面不需要side header栏 会出现闪屏问题
  setTimeout(() => {
    document.getElementById('cccms-loader-home').style.display = 'none';
  }, 500);
});

router.afterEach((to) => {
  if (NProgress.isStarted()) {
    setTimeout(() => {
      NProgress.done(true);
    }, 100);
  }
});

export default router;
