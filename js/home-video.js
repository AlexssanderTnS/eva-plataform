(() => {
  const container = document.querySelector('[data-vimeo-embed]');
  if (!container) return;

  const configuredVideoId = String(container.dataset.vimeoId || '').trim();
  const videoId = configuredVideoId || '1226795866';

  if (!/^\d+$/.test(videoId)) return;

  const iframe = document.createElement('iframe');
  iframe.src = `https://player.vimeo.com/video/${videoId}?badge=0&autopause=0&player_id=0&app_id=58479&dnt=1`;
  iframe.title = 'EVAInstitucional';
  iframe.allow = 'autoplay; fullscreen; picture-in-picture; clipboard-write; encrypted-media; web-share';
  iframe.allowFullscreen = true;
  iframe.loading = 'lazy';
  iframe.referrerPolicy = 'strict-origin-when-cross-origin';

  container.replaceChildren(iframe);
})();
