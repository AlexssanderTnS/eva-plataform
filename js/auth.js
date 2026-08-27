const API_BASE = './api/auth';

function showMessage(element, text, success = false) {
  if (!element) return;

  element.textContent = text || '';
  element.classList.toggle('is-success', success);
  element.classList.toggle('is-error', Boolean(text) && !success);
}

async function readJsonResponse(response) {
  try {
    return await response.json();
  } catch {
    return null;
  }
}

/* =========================================
   Abas de autenticação
   ========================================= */

const tabs = document.querySelectorAll('[data-auth-tab]');
const panels = document.querySelectorAll('[data-auth-panel]');
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

tabs.forEach((tab) => {
  tab.addEventListener('click', () => {
    switchAuthPanel(tab.dataset.authTab);
  });
});

switchAuthButtons.forEach((button) => {
  button.addEventListener('click', () => {
    switchAuthPanel(button.dataset.switchAuth);
  });
});

/* =========================================
   Cadastro
   ========================================= */

const registerForm = document.querySelector('#register-form');
const registerMessage = document.querySelector('[data-register-message]');

registerForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  if (!registerForm.checkValidity()) {
    registerForm.reportValidity();
    return;
  }

  const submitButton = registerForm.querySelector('button[type="submit"]');
  const formData = new FormData(registerForm);

  const payload = {
    first_name: formData.get('first_name')?.toString().trim() || '',
    last_name: formData.get('last_name')?.toString().trim() || '',
    email: formData.get('email')?.toString().trim() || '',
    password: formData.get('password')?.toString() || '',
  };

  showMessage(registerMessage, '');

  if (submitButton) submitButton.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/register.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    const data = await readJsonResponse(response);

    if (!response.ok) {
      showMessage(
        registerMessage,
        data?.message || 'Não foi possível criar sua conta.',
      );
      return;
    }

    showMessage(
      registerMessage,
      data?.message || 'Verifique seu e-mail para continuar.',
      true,
    );

    registerForm.reset();
  } catch (error) {
    console.error('Erro ao criar conta:', error);
    showMessage(
      registerMessage,
      'Não foi possível conectar com o servidor. Tente novamente.',
    );
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
});

/* =========================================
   Login e reenvio de confirmação
   ========================================= */

const loginForm = document.querySelector('#login-form');
const loginMessage = document.querySelector('[data-login-message]');
const resendBox = document.querySelector('[data-resend-box]');
const resendButton = document.querySelector('[data-resend-verification]');
const resendMessage = document.querySelector('[data-resend-message]');

let pendingVerificationEmail = '';

loginForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  if (!loginForm.checkValidity()) {
    loginForm.reportValidity();
    return;
  }

  const submitButton = loginForm.querySelector('button[type="submit"]');
  const formData = new FormData(loginForm);

  const payload = {
    email: formData.get('email')?.toString().trim() || '',
    password: formData.get('password')?.toString() || '',
  };

  pendingVerificationEmail = payload.email;
  showMessage(loginMessage, '');
  showMessage(resendMessage, '');

  if (resendBox) resendBox.hidden = true;
  if (submitButton) submitButton.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/login.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    const data = await readJsonResponse(response);

    if (!response.ok) {
      showMessage(
        loginMessage,
        data?.message || 'Não foi possível entrar na sua conta.',
      );

      if (
        response.status === 403 &&
        data?.email_verification_required === true &&
        resendBox
      ) {
        resendBox.hidden = false;
      }

      return;
    }

    showMessage(loginMessage, 'Login realizado com sucesso.', true);

    const pendingCourse = sessionStorage.getItem('eva_pending_course');

    if (pendingCourse) {
      sessionStorage.removeItem('eva_pending_course');
      window.location.assign(
        './recursos.html?resume_checkout=' + encodeURIComponent(pendingCourse),
      );
      return;
    }

    window.location.assign('./conta.html');
  } catch (error) {
    console.error('Erro ao realizar login:', error);
    showMessage(
      loginMessage,
      'Não foi possível conectar com o servidor. Tente novamente.',
    );
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
});

resendButton?.addEventListener('click', async () => {
  const email = pendingVerificationEmail ||
    document.querySelector('#login-email')?.value.trim() || '';

  if (!email) {
    showMessage(resendMessage, 'Informe seu e-mail para reenviar a confirmação.');
    return;
  }

  resendButton.disabled = true;
  showMessage(resendMessage, '');

  try {
    const response = await fetch(`${API_BASE}/resend-verification.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ email }),
    });

    const data = await readJsonResponse(response);

    showMessage(
      resendMessage,
      data?.message || 'Não foi possível processar o reenvio.',
      response.ok,
    );
  } catch (error) {
    console.error('Erro ao reenviar confirmação:', error);
    showMessage(resendMessage, 'Não foi possível conectar com o servidor.');
  } finally {
    resendButton.disabled = false;
  }
});

/* =========================================
   Minha conta
   ========================================= */

const accountRoot = document.querySelector('[data-account-root]');
const accountLoading = document.querySelector('[data-account-loading]');
const accountName = document.querySelector('[data-account-name]');
const profileFirstName = document.querySelector('#profile-first-name');
const profileLastName = document.querySelector('#profile-last-name');
const profileEmail = document.querySelector('#profile-email');

async function loadAccount() {
  if (!accountRoot) return;

  try {
    const response = await fetch(`${API_BASE}/me.php`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    const data = await readJsonResponse(response);

    if (response.status === 401 || response.status === 403) {
      window.location.replace('./acesso.html');
      return;
    }

    if (!response.ok || !data?.user) {
      if (accountLoading) {
        accountLoading.textContent =
          data?.message || 'Não foi possível carregar sua conta.';
      }
      return;
    }

    const user = data.user;

    if (accountName) accountName.textContent = user.first_name || 'usuário';
    if (profileFirstName) profileFirstName.value = user.first_name || '';
    if (profileLastName) profileLastName.value = user.last_name || '';
    if (profileEmail) profileEmail.value = user.email || '';

    accountRoot.hidden = false;
    if (accountLoading) accountLoading.hidden = true;
  } catch (error) {
    console.error('Erro ao carregar conta:', error);

    if (accountLoading) {
      accountLoading.textContent = 'Não foi possível carregar sua conta.';
    }
  }
}

loadAccount();

/* =========================================
   Atualização de perfil
   ========================================= */

const profileForm = document.querySelector('#profile-form');
const profileMessage = document.querySelector('[data-profile-message]');

profileForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  if (!profileForm.checkValidity()) {
    profileForm.reportValidity();
    return;
  }

  const submitButton = profileForm.querySelector('button[type="submit"]');
  const formData = new FormData(profileForm);
  const payload = {
    first_name: formData.get('first_name')?.toString().trim() || '',
    last_name: formData.get('last_name')?.toString().trim() || '',
  };

  showMessage(profileMessage, '');
  if (submitButton) submitButton.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/update-profile.php`, {
      method: 'PATCH',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    const data = await readJsonResponse(response);

    if (response.status === 401 || response.status === 403) {
      window.location.replace('./acesso.html');
      return;
    }

    if (!response.ok) {
      showMessage(profileMessage, data?.message || 'Não foi possível atualizar seu perfil.');
      return;
    }

    if (accountName) accountName.textContent = payload.first_name;
    showMessage(profileMessage, data?.message || 'Perfil atualizado com sucesso.', true);
  } catch (error) {
    console.error('Erro ao atualizar perfil:', error);
    showMessage(profileMessage, 'Não foi possível conectar com o servidor.');
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
});

/* =========================================
   Alteração de senha
   ========================================= */

const changePasswordForm = document.querySelector('#change-password-form');
const passwordMessage = document.querySelector('[data-password-message]');

changePasswordForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  if (!changePasswordForm.checkValidity()) {
    changePasswordForm.reportValidity();
    return;
  }

  const submitButton = changePasswordForm.querySelector('button[type="submit"]');
  const formData = new FormData(changePasswordForm);
  const payload = {
    current_password: formData.get('current_password')?.toString() || '',
    new_password: formData.get('new_password')?.toString() || '',
    new_password_confirmation:
      formData.get('new_password_confirmation')?.toString() || '',
  };

  showMessage(passwordMessage, '');

  if (payload.new_password !== payload.new_password_confirmation) {
    showMessage(passwordMessage, 'As novas senhas não coincidem.');
    return;
  }

  if (submitButton) submitButton.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/change-password.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify(payload),
    });

    const data = await readJsonResponse(response);

    if (response.status === 401 && data?.message === 'Não autenticado.') {
      window.location.replace('./acesso.html');
      return;
    }

    if (!response.ok) {
      showMessage(passwordMessage, data?.message || 'Não foi possível alterar sua senha.');
      return;
    }

    changePasswordForm.reset();
    showMessage(passwordMessage, data?.message || 'Senha alterada com sucesso.', true);
  } catch (error) {
    console.error('Erro ao alterar senha:', error);
    showMessage(passwordMessage, 'Não foi possível conectar com o servidor.');
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
});

/* =========================================
   Exportação de dados
   ========================================= */

const exportButton = document.querySelector('[data-export-data]');
const accountMessage = document.querySelector('[data-account-message]');

exportButton?.addEventListener('click', async () => {
  exportButton.disabled = true;
  showMessage(accountMessage, '');

  try {
    const response = await fetch(`${API_BASE}/export-data.php`, {
      method: 'GET',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
      cache: 'no-store',
    });

    const data = await readJsonResponse(response);

    if (response.status === 401 || response.status === 403) {
      window.location.replace('./acesso.html');
      return;
    }

    if (!response.ok || !data) {
      showMessage(accountMessage, data?.message || 'Não foi possível exportar seus dados.');
      return;
    }

    const blob = new Blob([JSON.stringify(data, null, 2)], {
      type: 'application/json;charset=utf-8',
    });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = 'eva-meus-dados.json';
    document.body.appendChild(link);
    link.click();
    link.remove();
    URL.revokeObjectURL(url);

    showMessage(accountMessage, 'Seus dados foram exportados.', true);
  } catch (error) {
    console.error('Erro ao exportar dados:', error);
    showMessage(accountMessage, 'Não foi possível conectar com o servidor.');
  } finally {
    exportButton.disabled = false;
  }
});

/* =========================================
   Solicitação de exclusão da conta
   ========================================= */

const deleteAccountForm = document.querySelector('#delete-account-form');
const deleteAccountMessage = document.querySelector('[data-delete-account-message]');

deleteAccountForm?.addEventListener('submit', async (event) => {
  event.preventDefault();

  if (!deleteAccountForm.checkValidity()) {
    deleteAccountForm.reportValidity();
    return;
  }

  const submitButton = deleteAccountForm.querySelector('button[type="submit"]');
  const formData = new FormData(deleteAccountForm);
  const password = formData.get('password')?.toString() || '';

  showMessage(deleteAccountMessage, '');
  if (submitButton) submitButton.disabled = true;

  try {
    const response = await fetch(`${API_BASE}/request-account-deletion.php`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Accept: 'application/json',
      },
      credentials: 'same-origin',
      body: JSON.stringify({ password }),
    });

    const data = await readJsonResponse(response);

    if (response.status === 401 && data?.message === 'Não autenticado.') {
      window.location.replace('./acesso.html');
      return;
    }

    if (!response.ok) {
      showMessage(
        deleteAccountMessage,
        data?.message || 'Não foi possível registrar a solicitação.',
      );
      return;
    }

    deleteAccountForm.reset();
    showMessage(
      deleteAccountMessage,
      data?.message || 'Sua solicitação de exclusão foi registrada.',
      true,
    );
  } catch (error) {
    console.error('Erro ao solicitar exclusão da conta:', error);
    showMessage(deleteAccountMessage, 'Não foi possível conectar com o servidor.');
  } finally {
    if (submitButton) submitButton.disabled = false;
  }
});

/* =========================================
   Logout
   ========================================= */

const logoutButton = document.querySelector('[data-logout]');

logoutButton?.addEventListener('click', async () => {
  logoutButton.disabled = true;

  try {
    await fetch(`${API_BASE}/logout.php`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
  } catch (error) {
    console.error('Erro ao encerrar sessão:', error);
  } finally {
    window.location.replace('./acesso.html');
  }
});
