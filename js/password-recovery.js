(() => {
  const requestForm = document.querySelector('#recovery-request-form');
  const resetForm = document.querySelector('#recovery-reset-form');
  const message = document.querySelector('#recovery-message');
  const title = document.querySelector('#recovery-title');
  const description = document.querySelector('#recovery-description');
  const fragment = new URLSearchParams(window.location.hash.slice(1));
  let token = fragment.get('token') || '';
  const hasToken = fragment.has('token');
  if (hasToken) {
    window.history.replaceState(null, '', window.location.pathname + window.location.search);
    requestForm.hidden = true;
    if (/^[a-f0-9]{64}$/.test(token)) {
      resetForm.hidden = false;
      title.textContent = 'Criar nova senha';
      description.textContent = 'Escolha sua nova senha. Após salvar, entre novamente na sua conta.';
    } else {
      message.textContent = 'Link inválido. Solicite um novo link abaixo.';
      message.classList.add('is-error');
    }
  }
  let busy = false;
  async function submit(event, reset) {
    event.preventDefault();
    if (busy) return;
    const form = event.currentTarget;
    if (!form.reportValidity()) return;
    const fields = new FormData(form);
    const payload = reset
      ? {token, password: fields.get('password'), password_confirmation: fields.get('password_confirmation')}
      : {email: fields.get('email')};
    message.classList.remove('is-success', 'is-error');
    if (reset && payload.password !== payload.password_confirmation) {
      message.textContent = 'As senhas não coincidem.';
      message.classList.add('is-error');
      return;
    }
    busy = true;
    const button = form.querySelector('button[type="submit"]');
    button.disabled = true;
    const label = button.textContent;
    button.textContent = reset ? 'Salvando...' : 'Enviando...';
    message.textContent = '';
    const controller = new AbortController();
    const timer = window.setTimeout(() => controller.abort(), 25000);
    try {
      const response = await fetch(`./api/auth/${reset ? 'reset-password' : 'forgot-password'}.php`, {
        method: 'POST',
        headers: {'Content-Type': 'application/json', Accept: 'application/json'},
        credentials: 'same-origin',
        cache: 'no-store',
        signal: controller.signal,
        body: JSON.stringify(payload),
      });
      const data = await response.json().catch(() => null);
      if (!response.ok || data?.success !== true) throw new Error(data?.message || 'Não foi possível concluir. Tente novamente.');
      message.textContent = data.message;
      message.classList.add('is-success');
      if (reset) {
        token = '';
        form.reset();
        form.hidden = true;
        description.textContent = 'Sua nova senha foi salva. Use o link abaixo para entrar.';
      }
    } catch (error) {
      message.textContent = error.name === 'AbortError' ? 'A resposta demorou. Confira seu e-mail ou tente entrar antes de repetir.' : error.message;
      message.classList.add('is-error');
    } finally {
      window.clearTimeout(timer);
      button.disabled = false;
      button.textContent = label;
      busy = false;
    }
  }
  requestForm.addEventListener('submit', event => submit(event, false));
  resetForm.addEventListener('submit', event => submit(event, true));
})();
