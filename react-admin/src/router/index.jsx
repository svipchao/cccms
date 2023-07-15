import { lazy, Suspense } from "react";
import { Spin } from "antd";

// 实现懒加载的用Suspense包裹 定义函数
const lazyLoad = (path) => {
  const Compontents = lazy(() => import(/* @vite-ignore */ path));
  return (
    <Suspense
      fallback={
        <Spin
          size="large"
          style={{
            display: "flex",
            alignItems: "center",
            justifyContent: "center",
            height: "100vh",
          }}
        />
      }
    >
      <Compontents />
    </Suspense>
  );
};

export const routers = [
  {
    path: "/",
    element: lazyLoad("../layouts/index.jsx"),
    // 路由嵌套，子路由的元素需使用<Outlet />
    children: [
      {
        index: true,
        element: lazyLoad("../pages/home.jsx"),
      },
      {
        path: "/about",
        element: lazyLoad("../pages/about.jsx"),
      },
    ],
  },
  {
    path: "/login",
    element: lazyLoad("../pages/about.jsx"),
  },
];
