(() => {
  const nativeFetch = window.fetch.bind(window);

  window.fetch = async (input, options = {}) => {
    if (options.signal) {
      return nativeFetch(input, options);
    }

    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 15000);

    try {
      return await nativeFetch(input, {
        ...options,
        signal: controller.signal,
      });
    } finally {
      window.clearTimeout(timer);
    }
  };
})();
