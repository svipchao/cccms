import { createGetRoutes, setupLayouts } from 'virtual:meta-layouts';
import { createRouter, createWebHistory } from 'vue-router';
// import { routes as fileRoutes } from 'vue-router/auto-routes';

declare module 'vue-router' {
  // 在这里定义你的 meta 类型
  // eslint-disable-next-line no-unused-vars
  interface RouteMeta {
    title?: string;
    layout?: string;
  }
}

// console.log(fileRoutes);
const fileRoutes = [
  {
    name: '/login',
    path: '/login',
    meta: { title: '登录', layout: 'notFound' },
    component: () => import('@/pages/admin/user/login.vue'),
  },
  {
    name: '/[...notFound]',
    path: '/:notFound(.*)',
    meta: { title: '404', layout: 'notFound' },
    component: () => import('@/layouts/[...notFound].vue'),
  },
  {
    name: '/admin/index/index',
    path: '/admin/index/index',
    meta: { title: '控制台', layout: 'default' },
    component: () => import('@/pages/admin/index/index.vue'),
  },
  {
    name: '/admin/index/aaa',
    path: '/admin/index/aaa',
    meta: { title: '控制台', layout: 'default' },
    component: () => import('@/pages/admin/index/aaa.md'),
  },
];

// 重定向 BASE_URL
fileRoutes.flat(Infinity).forEach((route) => {
  route.path = safeResolve(route.path);
});

// const myRoutes = [
//   {
//     name: 'layouts',
//     path: '/',
//     redirect: '/admin1/index/index',
//     meta: { title: '首页', layout: 'notFound' },
//   },
// ];
// console.log(setupLayouts([...myRoutes, ...fileRoutes]));

export const router = createRouter({
  history: createWebHistory(),
  routes: setupLayouts(fileRoutes),
  // routes: setupLayouts([...myRoutes, ...fileRoutes]),
});

export const getRoutes = createGetRoutes(router);

export default router;
