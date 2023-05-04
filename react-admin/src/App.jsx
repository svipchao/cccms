import { ConfigProvider, theme } from "antd";
import { routers } from "./router/index";
import { useRoutes } from "react-router-dom";
import { useThemeStore } from "./store/theme";

export default function App() {
  const { darkTheme } = useThemeStore();

  return (
    <>
      <ConfigProvider
        theme={{
          algorithm: darkTheme ? theme.defaultAlgorithm : theme.darkAlgorithm,
          components: {
            Layout: {
              colorBgBase: "#fff",
            },
          },
        }}
      >
        {useRoutes(routers)}
      </ConfigProvider>
    </>
  );
}
