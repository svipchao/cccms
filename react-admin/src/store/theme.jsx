import { create } from "zustand";
import { persist, createJSONStorage } from "zustand/middleware";

export const useThemeStore = create(
  persist(
    (set) => ({
      darkTheme: false,
      showSider: true,
      switchTheme: () => {
        set((state) => ({ darkTheme: !state.darkTheme }));
      },
      switchShowSider: () => {
        set((state) => ({ showSider: !state.showSider }));
      },
    }),
    {
      name: "cccms-theme",
      storage: createJSONStorage(() => localStorage),
    }
  )
);
