import { useCallback, useEffect, useState } from "react";

export const useFullScreen = () => {
  const [isFullScreen, setIsFullScreen] = useState(false);

  const enterFullScreen = useCallback(() => {
    if (!document.fullscreenElement) {
      document.documentElement.requestFullscreen();
    }
  }, []);

  const exitFullScreen = useCallback(() => {
    if (document.fullscreenElement) {
      document.exitFullscreen();
    }
  }, []);

  const toggleFullScreen = useCallback(() => {
    if (document.fullscreenElement) {
      document.exitFullscreen();
    } else {
      document.documentElement.requestFullscreen();
    }
  });

  useEffect(() => {
    const onFullScreenChange = () => {
      setIsFullScreen(!!document.fullscreenElement);
    };

    document.addEventListener("fullscreenchange", onFullScreenChange);

    return () => {
      document.removeEventListener("fullscreenchange", onFullScreenChange);
    };
  }, []);

  return {
    isFullScreen,
    enterFullScreen,
    exitFullScreen,
    toggleFullScreen,
  };
};
