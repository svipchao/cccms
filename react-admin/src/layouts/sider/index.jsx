import React from "react";
import { Layout, theme, Button, Col, Row, Space, Divider, Menu } from "antd";
import { useThemeStore } from "@/store/theme";
import Userinfo from "./components/userinfo";

function getItem(label, key, icon, children, type) {
  console.log({
    key,
    icon,
    children,
    label,
    type,
  });
  return {
    key,
    icon,
    children,
    label,
    type,
  };
}
const items = [
  getItem("Option 1", "1", ""),
  getItem("Option 2", "2", ""),
  getItem("Option 3", "3", ""),
  getItem("Navigation One", "sub1", "", [
    getItem("Option 5", "5", ""),
    getItem("Option 6", "6", ""),
    getItem("Option 7", "7", ""),
    getItem("Option 8", "8", ""),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
  getItem("Navigation Two", "sub2", "", [
    getItem("Option 9", "9", ""),
    getItem("Option 10", "10", ""),
    getItem("Submenu", "sub3", null, "", [
      getItem("Option 11", "11", ""),
      getItem("Option 12", "12", ""),
    ]),
  ]),
];
export default function Sider() {
  const { Sider } = Layout;
  const { token } = theme.useToken();
  const { darkTheme, switchTheme, showSider } = useThemeStore();

  return (
    <Sider
      theme="light"
      width={230}
      collapsed={showSider}
      collapsedWidth={0}
      style={{ background: token.colorBgContainer }}
    >
      <Space direction="vertical" className="ant-layout-space">
        <Userinfo />
        <Row className="ant-layout-sider-button" wrap={false}>
          <Col flex="auto">
            <Button type="text" icon={<i className="ri-apps-line"></i>} />
          </Col>
          <Col flex="10px">
            <Divider type="vertical" />
          </Col>
          <Col flex="auto">
            <Button
              type="text"
              onClick={() => switchTheme(!darkTheme)}
              icon={<i className={darkTheme ? "ri-sun-fill" : "ri-moon-fill"}></i>}
            />
          </Col>
          <Col flex="10px">
            <Divider type="vertical" />
          </Col>
          <Col flex="auto">
            <Button type="text" icon={<i className="ri-refresh-line"></i>} />
          </Col>
          <Col flex="10px">
            <Divider type="vertical" />
          </Col>
          <Col flex="auto">
            <Button type="text" icon={<i className="ri-logout-box-line"></i>} />
          </Col>
        </Row>
        <Divider className="ant-layout-sider-divider" />
        <Menu
          className="ant-layout-sider-menu"
          defaultSelectedKeys={["1"]}
          defaultOpenKeys={["sub1"]}
          mode="inline"
          items={items}
        />
      </Space>
    </Sider>
  );
}
