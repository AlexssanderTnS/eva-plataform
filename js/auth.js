const API_BASE = './api/auth';

function showMessage(element, text, success = false) {
  if (!element) return;
  element.textContent = text || '';
  element.classList.toggle('is-success', success);
  element.classList.toggle('is-error', Boolean(text) && !success);
}

const tabs = document.querySelectorAll('[data-auth-tab]');
const panels = document.querySelectorAll('[data-auth-panel]');

tabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    const target = tab.dataset.authTab;
    tabs.forEach((item) => {
      const active = item === tab;
      item.classList.toggle('is-active', active);
      item.setAttribute('aria-selected', String(active));
    });
    panels.forEach((panel) => {
      const active = panel.dataset.authPanel === target;
      panel.classList.toggle('is-active', active);
      panel.hidden = !active;
    });
  });
});
