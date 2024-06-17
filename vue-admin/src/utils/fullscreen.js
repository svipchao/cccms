/* eslint-disable promise/prefer-await-to-then */

const methodMap = [
  [
    "requestFullscreen",
    "exitFullscreen",
    "fullscreenElement",
    "fullscreenEnabled",
    "fullscreenchange",
    "fullscreenerror",
  ],
  // New WebKit
  [
    "webkitRequestFullscreen",
    "webkitExitFullscreen",
    "webkitFullscreenElement",
    "webkitFullscreenEnabled",
    "webkitfullscreenchange",
    "webkitfullscreenerror",
  ],
  // Old WebKit
  [
    "webkitRequestFullScreen",
    "webkitCancelFullScreen",
    "webkitCurrentFullScreenElement",
    "webkitCancelFullScreen",
    "webkitfullscreenchange",
    "webkitfullscreenerror",
  ],
  [
    "mozRequestFullScreen",
    "mozCancelFullScreen",
    "mozFullScreenElement",
    "mozFullScreenEnabled",
    "mozfullscreenchange",
    "mozfullscreenerror",
  ],
  [
    "msRequestFullscreen",
    "msExitFullscreen",
    "msFullscreenElement",
    "msFullscreenEnabled",
    "MSFullscreenChange",
    "MSFullscreenError",
  ],
];

const nativeAPI = (() => {
  if (typeof document === "undefined") {
    return false;
  }

  const unprefixedMethods = methodMap[0];
  const returnValue = {};

  for (const methodList of methodMap) {
    const exitFullscreenMethod = methodList?.[1];
    if (exitFullscreenMethod in document) {
      for (const [index, method] of methodList.entries()) {
        returnValue[unprefixedMethods[index]] = method;
      }

      return returnValue;
    }
  }

  return false;
})();

const eventNameMap = {
  change: nativeAPI.fullscreenchange,
  error: nativeAPI.fullscreenerror,
};

// eslint-disable-next-line import/no-mutable-exports
let fullscreen = {
  // eslint-disable-next-line default-param-last
  request(element = document.documentElement, options) {
    return new Promise((resolve, reject) => {
      const onFullScreenEntered = () => {
        fullscreen.off("change", onFullScreenEntered);
        resolve();
      };

      fullscreen.on("change", onFullScreenEntered);

      const returnPromise = element[nativeAPI.requestFullscreen](options);

      if (returnPromise instanceof Promise) {
        returnPromise.then(onFullScreenEntered).catch(reject);
      }
    });
  },
  exit() {
    return new Promise((resolve, reject) => {
      if (!fullscreen.isFullscreen) {
        resolve();
        return;
      }

      const onFullScreenExit = () => {
        fullscreen.off("change", onFullScreenExit);
        resolve();
      };

      fullscreen.on("change", onFullScreenExit);

      const returnPromise = document[nativeAPI.exitFullscreen]();

      if (returnPromise instanceof Promise) {
        returnPromise.then(onFullScreenExit).catch(reject);
      }
    });
  },
  toggle(element, options) {
    return fullscreen.isFullscreen ? fullscreen.exit() : fullscreen.request(element, options);
  },
  onchange(callback) {
    fullscreen.on("change", callback);
  },
  onerror(callback) {
    fullscreen.on("error", callback);
  },
  on(event, callback) {
    const eventName = eventNameMap[event];
    if (eventName) {
      document.addEventListener(eventName, callback, false);
    }
  },
  off(event, callback) {
    const eventName = eventNameMap[event];
    if (eventName) {
      document.removeEventListener(eventName, callback, false);
    }
  },
  raw: nativeAPI,
};

Object.defineProperties(fullscreen, {
  isFullscreen: {
    get: () => Boolean(document[nativeAPI.fullscreenElement]),
  },
  element: {
    enumerable: true,
    get: () => document[nativeAPI.fullscreenElement] ?? undefined,
  },
  isEnabled: {
    enumerable: true,
    // Coerce to boolean in case of old WebKit.
    get: () => Boolean(document[nativeAPI.fullscreenEnabled]),
  },
});

if (!nativeAPI) {
  fullscreen = { isEnabled: false };
}

export default fullscreen;
