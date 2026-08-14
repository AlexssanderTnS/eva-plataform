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

const switchAuthButtons = document.querySelectorAll('[data-switch-auth]');

function switchAuthPanel(target) {
  tabs.forEach((tab) => {
    const active = tab.dataset.authTab === target;

    tab.classList.toggle('is-active', active);
    tab.setAttribute('aria-selected', String(active));
  });

  panels.forEach((panel) => {
    const active = panel.dataset.authPanel === target;

    panel.classList.toggle('is-active', active);
    panel.hidden = !active;
  });
}

switchAuthButtons.forEach((button) => {
  button.addEventListener('click', () => {
    switchAuthPanel(button.dataset.switchAuth);
  });
});

const registerForm = document.querySelector('#register-form');
const registerMessage = document.querySelector('[data-register-message]');

registerForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  const submitButton = registerForm.querySelector('button[type="submit"]');
  const formData = new FormData(registerForm);

  const payload = {
    first_name: formData.get('first_name')?.toString().trim() || '',
    last_name: formData.get('last_name')?.toString().trim() || '',
    email: formData.get('email')?.toString().trim() || '',
    password: formData.get('password')?.toString() || '',
  };

  showMessage(registerMessage, '');

  if (!registerForm.checkValidity()) {
    registerForm.reportValidity();
    return;
  }

  submitButton.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/register.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
      },
      body: JSON.stringify(payload),
    });

    const data = await response.json();

    if (!response.ok) {
      showMessage(
        registerMessage,
        data.message || 'Não foi possível criar sua conta.',
      );
      return;
    }

    showMessage(
      registerMessage,
      data.message || 'Conta criada. Verifique seu e-mail para continuar.',
      true,
    );

    registerForm.reset();
  } catch (error) {
    console.error(error);

    showMessage(
      registerMessage,
      'Não foi possível conectar com o servidor. Tente novamente.',
    );
  } finally {
    submitButton.disabled = false;
  }
});