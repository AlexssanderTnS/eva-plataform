(() => {
  const coursesBox = document.querySelector('.account-courses-empty');
  if (!coursesBox) return;

  const courseParam = new URLSearchParams(window.location.search).get('curso') || '';
  const targetSlug = /^[a-z0-9]+(?:-[a-z0-9]+)*$/.test(courseParam) ? courseParam : '';

  function formatDate(value) {
    if (!value) return '';
    const normalized = value.includes('T') ? value : value.replace(' ', 'T');
    const date = new Date(normalized);
    if (Number.isNaN(date.getTime())) return '';
    return new Intl.DateTimeFormat('pt-BR', {
      day: '2-digit',
      month: '2-digit',
      year: 'numeric',
    }).format(date);
  }

  function statusLabel(status) {
    if (status === 'active') return 'Disponível';
    if (status === 'pending') return 'Liberando acesso';
    return 'Acesso encerrado';
  }

  function statusDescription(course) {
    if (course.status === 'active') {
      const grantedDate = formatDate(course.granted_at);
      return grantedDate ? `Acesso liberado em ${grantedDate}.` : 'Seu acesso ao curso está liberado.';
    }
    if (course.status === 'pending') {
      return 'Seu pagamento foi confirmado e estamos concluindo a liberação do curso.';
    }
    const revokedDate = formatDate(course.revoked_at);
    return revokedDate ? `Acesso encerrado em ${revokedDate}.` : 'Este acesso não está mais disponível.';
  }

  function renderLoadingState() {
    coursesBox.classList.remove('is-loaded');
    coursesBox.innerHTML = `
      <div class="account-course-skeleton-list" aria-hidden="true">
        <div class="account-course-skeleton"><span></span><span></span><span></span></div>
        <div class="account-course-skeleton"><span></span><span></span><span></span></div>
      </div>
    `;
  }

  function renderEmptyState() {
    coursesBox.classList.remove('is-loaded');
    coursesBox.innerHTML = `
      <strong>Você ainda não possui cursos liberados.</strong>
      <p>Conheça os cursos disponíveis e continue sua jornada com a EVA.</p>
      <a class="account-course-browse" href="./recursos.html">
        Ver cursos
        <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>
      </a>
    `;
  }

  function renderErrorState(message) {
    coursesBox.classList.remove('is-loaded');
    coursesBox.innerHTML = `
      <strong>Não foi possível carregar seus cursos agora.</strong>
      <p>${message}</p>
      <button type="button" class="account-secondary-button account-course-retry" data-course-retry>Tentar novamente</button>
    `;
    coursesBox.querySelector('[data-course-retry]')?.addEventListener('click', loadCourses);
  }

  async function openCourse(course, button, message) {
    if (button.disabled) return;
    const originalText = button.innerHTML;
    button.disabled = true;
    button.innerHTML = '<span>Abrindo curso...</span>';
    if (message) message.textContent = '';

    try {
      const response = await fetch('./api/account/sso.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
        },
        body: JSON.stringify({ slug: course.slug }),
      });
      const data = await response.json().catch(() => null);

      if ((response.status === 401 || response.status === 403) && data?.message === 'Não autenticado.') {
        window.location.replace('./acesso.html');
        return;
      }

      if (!response.ok || !data?.url) {
        throw new Error(data?.message || 'Não foi possível abrir o curso.');
      }

      window.location.assign(data.url);
    } catch (error) {
      console.error('Erro ao abrir curso:', error);
      if (message) {
        message.textContent = error.name === 'AbortError'
          ? 'A abertura do curso demorou mais que o esperado. Tente novamente.'
          : error.message || 'Não foi possível abrir o curso.';
      }
      button.disabled = false;
      button.innerHTML = originalText;
    }
  }

  function renderCourses(courses) {
    coursesBox.classList.add('is-loaded');
    coursesBox.innerHTML = '';
    const list = document.createElement('div');
    list.className = 'account-course-list';

    courses.forEach((course) => {
      const item = document.createElement('article');
      item.className = `account-course-item is-${course.status}`;
      if (targetSlug && course.slug === targetSlug) {
        item.classList.add('is-target');
        item.setAttribute('aria-label', `Curso da sua compra: ${course.title}`);
      }
      const badge = document.createElement('span');
      badge.className = `account-course-status is-${course.status}`;
      badge.textContent = statusLabel(course.status);
      const title = document.createElement('h3');
      title.textContent = course.title;
      const description = document.createElement('p');
      description.textContent = statusDescription(course);
      item.append(badge, title, description);

      if (course.status === 'active' && course.access_ready === true) {
        const accessNote = document.createElement('div');
        accessNote.className = 'account-course-ready';
        accessNote.innerHTML = `
          <span class="material-symbols-rounded" aria-hidden="true">check_circle</span>
          <span>Curso liberado no ambiente de aprendizagem.</span>
        `;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'account-course-access';
        button.innerHTML = `
          <span>Acessar curso</span>
          <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>
        `;
        const message = document.createElement('div');
        message.className = 'account-course-message';
        message.setAttribute('aria-live', 'polite');
        button.addEventListener('click', () => openCourse(course, button, message));
        item.append(accessNote, button, message);
      }

      list.append(item);
    });

    coursesBox.append(list);

    const target = list.querySelector('.account-course-item.is-target');
    if (target && document.querySelector('[data-account-root]')?.hidden === false) {
      window.requestAnimationFrame(() => target.scrollIntoView({ block: 'center' }));
    }
  }

  async function loadCourses() {
    renderLoadingState();

    try {
      const response = await fetch('./api/account/courses.php', {
        method: 'GET',
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      const data = await response.json().catch(() => null);

      if (response.status === 401 || response.status === 403) {
        const loginUrl = window.location.hash === '#meus-cursos' || targetSlug
          ? './acesso.html?next=meus-cursos' + (targetSlug ? '&curso=' + encodeURIComponent(targetSlug) : '')
          : './acesso.html';
        window.location.replace(loginUrl);
        return;
      }

      if (!response.ok || !Array.isArray(data?.courses)) {
        throw new Error(data?.message || 'Não foi possível carregar seus cursos.');
      }

      if (data.courses.length === 0) {
        renderEmptyState();
        return;
      }

      renderCourses(data.courses);
    } catch (error) {
      console.error('Erro ao carregar cursos:', error);
      renderErrorState(
        error.name === 'AbortError'
          ? 'A resposta demorou mais que o esperado. Você pode tentar novamente sem recarregar a página.'
          : 'Tente novamente. Se o problema continuar, entre em contato com a EVA.',
      );
    }
  }

  loadCourses();
})();
