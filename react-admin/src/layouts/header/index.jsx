import React from "react";
import { Layout, Col, Row, theme, Button } from "antd";
import { useThemeStore } from "@/store/theme";
import fullscreen from "@/utils/functions/fullscreen.js";

export default function Header() {
  const { Header } = Layout;
  const { token } = theme.useToken();
  const { showSider, switchShowSider } = useThemeStore();
  console.log(fullscreen);
  return (
    <Header style={{ background: token.colorBgContainer }}>
      <Row>
        <Col span={12}>
          <div className="header-custom-box">
            <Button
              type="text"
              onClick={() => switchShowSider()}
              icon={<i className={showSider ? "ri-menu-unfold-line" : "ri-menu-fold-line"}></i>}
            />
          </div>
        </Col>
        <Col span={12} className="header-custom-right">
          <div className="header-custom-box">
            <Button
              type="text"
              onClick={() => fullscreen.toggle()}
              icon={
                <i
                  className={
                    fullscreen.isFullscreen ? "ri-fullscreen-exit-line" : "ri-fullscreen-line"
                  }
                ></i>
              }
            />
          </div>
        </Col>
      </Row>
    </Header>
  );
}
