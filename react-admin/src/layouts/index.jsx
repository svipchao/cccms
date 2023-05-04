import { Outlet } from "react-router-dom";
import { Layout, theme } from "antd";
import Sider from "./sider/index";
import Header from "./header/index";

export default function App() {
  const { Content } = Layout;
  const { token } = theme.useToken();

  return (
    <>
      <Layout>
        <Sider />
        <Layout>
          <Header />
          <Content>
            {/** 滚动条样式 暂时解决方案 */}
            <style>
              {`::-webkit-scrollbar{width:6px;height:6px;}::-webkit-scrollbar-thumb{border-radius: 5px;background-color:` +
                token.colorBorder +
                `;}`}
            </style>
            <Outlet />
          </Content>
        </Layout>
      </Layout>
    </>
  );
}
