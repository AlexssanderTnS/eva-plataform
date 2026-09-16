(() => {
  const container = document.querySelector('[data-vimeo-embed]');
  if (!container) return;

  const videoId = String(container.dataset.vimeoId || '').trim();
  if (!/^\d+$/.test(videoId)) return;

  const iframe = document.createElement('iframe');
  iframe.src = `https://player.vimeo.com/video/${videoId}?dnt=1&title=0&byline=0&portrait=0`;
  iframe.title = 'Vídeo institucional da EVA';
  iframe.allow = 'autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media';
  iframe.allowFullscreen = true;
  iframe.loading = 'lazy';
  iframe.referrerPolicy = 'strict-origin-when-cross-origin';

  container.replaceChildren(iframe);
})();
