import React from "react";
import { Avatar, Col, Row, Typography, Tooltip } from "antd";

export default function Userinfo() {
  const { Text, Title } = Typography;
  return (
    <Row className="ant-layout-sider-userinfo" wrap={false}>
      <Col flex="60px">
        <Tooltip title="点击修改个人信息" placement="bottomLeft">
          <Avatar size={50}>管理</Avatar>
        </Tooltip>
      </Col>
      <Col flex="auto">
        <Title
          level={5}
          className="username"
          ellipsis={{
            tooltip: true,
          }}
        >
          Admin
        </Title>
        <Text
          className="nickname"
          ellipsis={{
            tooltip: true,
          }}
        >
          超级管理员
        </Text>
      </Col>
    </Row>
  );
}
