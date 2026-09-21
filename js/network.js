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

  const footerColumns = document.querySelectorAll('.site-footer .footer-column');

  footerColumns.forEach((column) => {
    const heading = column.querySelector('h3');

    if (heading?.textContent.trim() !== 'Fale conosco') {
      return;
    }

    const placeholder = Array.from(column.querySelectorAll('.footer-disabled')).find(
      (item) => item.textContent.trim().toLowerCase().includes('whatsapp'),
    );

    if (!placeholder) {
      return;
    }

    const link = document.createElement('a');
    link.href = 'https://wa.me/5521973410015?text=Ol%C3%A1%21%20Vim%20pelo%20site%20da%20EVA%20e%20gostaria%20de%20conversar.';
    link.target = '_blank';
    link.rel = 'noopener noreferrer';
    link.textContent = 'WhatsApp: (21) 97341-0015';
    link.setAttribute('aria-label', 'Falar com a EVA pelo WhatsApp no número (21) 97341-0015');

    placeholder.replaceWith(link);
  });
})();
