const cursosIndividuais = [
  {
    id: "gestao-financeira-pessoal",
    titulo: "Gestão Financeira Pessoal",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "./assets/images/gestaoFinanceira.png",
    descricao:
      "Estratégias práticas para organizar o orçamento, controlar despesas, definir metas e desenvolver hábitos financeiros mais saudáveis.",
    descricaoCompleta:
      "Um curso prático para ajudar você a organizar sua vida financeira, compreender melhor seus gastos e construir decisões mais conscientes e sustentáveis no dia a dia.",
    aprendizados: [
      "Organizar seu orçamento pessoal",
      "Controlar gastos e despesas",
      "Definir metas financeiras realistas",
      "Criar hábitos financeiros mais saudáveis",
      "Tomar decisões financeiras com mais segurança",
    ],
  },
  {
    id: "micro-habitos-pessoais",
    titulo:
      "Micro-Hábitos Pessoais: Construindo Mudanças Sustentáveis no Dia a Dia",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "./assets/images/microPessoais.png",
    descricao:
      "Estratégias práticas para criar e manter hábitos positivos, fortalecer a disciplina e alcançar objetivos de forma consistente.",
    descricaoCompleta:
      "Aprenda a construir mudanças possíveis por meio de pequenos comportamentos consistentes, reduzindo a dependência de motivação e aumentando a chance de manter novos hábitos ao longo do tempo.",
    aprendizados: [
      "Entender como hábitos são formados",
      "Transformar objetivos em pequenas ações",
      "Criar rotinas mais sustentáveis",
      "Lidar melhor com interrupções e recaídas",
      "Acompanhar seu progresso de forma prática",
    ],
  },
  {
    id: "comunicacao-nao-violenta",
    titulo:
      "Comunicação Não Violenta na Prática: Transformando Relações Pessoais e Profissionais",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "./assets/images/comunicacaoNviolenta.png",
    descricao:
      "Aprenda a expressar suas necessidades com clareza, lidar melhor com conflitos e construir relações mais conscientes e respeitosas.",
    descricaoCompleta:
      "Conheça os princípios da Comunicação Não Violenta e pratique uma forma de se expressar com mais clareza, escuta e respeito, inclusive em conversas difíceis.",
    aprendizados: [
      "Identificar observações sem julgamentos",
      "Reconhecer sentimentos e necessidades",
      "Fazer pedidos claros e possíveis",
      "Escutar com mais empatia",
      "Conduzir conflitos de forma mais consciente",
    ],
  },
  {
    id: "regulacao-emocional",
    titulo: "Regulação Emocional",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "./assets/images/regulacaoEmocional.png",
    descricao:
      "Estratégias práticas para compreender e gerenciar emoções, lidar com situações de estresse e tomar decisões de maneira mais equilibrada.",
    descricaoCompleta:
      "Desenvolva recursos para reconhecer suas emoções, compreender o que elas sinalizam e responder a situações desafiadoras com mais consciência e equilíbrio.",
    aprendizados: [
      "Reconhecer emoções e gatilhos",
      "Compreender respostas emocionais",
      "Aplicar estratégias de autorregulação",
      "Lidar melhor com situações de estresse",
      "Tomar decisões com mais consciência",
    ],
  },
  {
    id: "comunicacao-empatica",
    titulo: "Comunicação Empática e Escuta Ativa",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "./assets/images/comunicacaoEmpatica.png",
    descricao:
      "Desenvolva uma comunicação mais clara e respeitosa por meio de técnicas de escuta ativa, empatia e comunicação assertiva.",
    descricaoCompleta:
      "Um curso para fortalecer conexões por meio de escuta ativa, empatia e comunicação assertiva, com ferramentas aplicáveis às relações pessoais e profissionais.",
    aprendizados: [
      "Praticar escuta ativa",
      "Identificar barreiras na comunicação",
      "Se expressar com mais clareza",
      "Desenvolver respostas mais empáticas",
      "Reduzir ruídos e conflitos nas relações",
    ],
  },
];

const COURSES_API = "./api/courses.php";
const CREATE_ORDER_API = "./api/orders/create.php";

const currencyFormatter = new Intl.NumberFormat("pt-BR", {
  style: "currency",
  currency: "BRL",
});

function formatCoursePrice(course) {
  if (typeof course.price !== "string" || course.price.trim() === "") {
    return "Preço indisponível";
  }

  const value = Number(course.price);
  return Number.isFinite(value)
    ? currencyFormatter.format(value)
    : "Preço indisponível";
}

async function loadCommercialCatalog() {
  const response = await fetch(COURSES_API, {
    method: "GET",
    headers: { Accept: "application/json" },
    cache: "no-store",
  });

  if (!response.ok) {
    throw new Error(`Não foi possível carregar o catálogo. HTTP ${response.status}`);
  }

  const data = await response.json();

  if (data?.success !== true || !Array.isArray(data.courses)) {
    throw new Error("Resposta inválida da API de cursos.");
  }

  const commercialCourses = new Map(
    data.courses.map((course) => [course.slug, course]),
  );

  cursosIndividuais.forEach((course) => {
    const commercialCourse = commercialCourses.get(course.id);

    if (!commercialCourse) {
      course.price = null;
      course.currency = null;
      course.available = false;
      return;
    }

    course.price = commercialCourse.price;
    course.currency = commercialCourse.currency;
    course.available = true;
  });
}

const track = document.querySelector("#resources-track");
const prevButton = document.querySelector(".resources-arrow-prev");
const nextButton = document.querySelector(".resources-arrow-next");
const modal = document.querySelector("#individual-course-modal");
const modalContent = document.querySelector("#individual-course-modal-content");

let currentIndex = 0;
let individualModalLastFocusedElement = null;
let checkoutInProgress = false;

function renderCursos() {
  if (!track) return;

  track.innerHTML = cursosIndividuais
    .map(
      (curso) => `
        <article class="resource-card">
          <div class="resource-card-visual">
            <img src="${curso.imagem}" alt="${curso.titulo}" loading="lazy" />
            <span class="resource-type">Curso online</span>
          </div>
          <div class="resource-card-content">
            <h3>${curso.titulo}</h3>
            <p>${curso.descricao}</p>
            <div class="resource-meta">
              <span class="material-symbols-rounded" aria-hidden="true">schedule</span>
              <span>${curso.duracao}</span>
              <span class="material-symbols-rounded" aria-hidden="true">devices</span>
              <span>${curso.formato}</span>
            </div>
            <button
              class="resource-button resource-details-button"
              type="button"
              data-course-id="${curso.id}"
              aria-haspopup="dialog"
              aria-controls="individual-course-modal"
            >
              Ver detalhes
              <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>
            </button>
          </div>
        </article>
      `,
    )
    .join("");
}

function renderModal(curso) {
  if (!modalContent) return;

  modalContent.innerHTML = `
    <div class="course-detail-layout">
      <div class="course-detail-visual">
        <img src="${curso.imagem}" alt="${curso.titulo}" />
      </div>
      <div class="course-detail-main">
        <span class="course-detail-type">Curso online</span>
        <h2 id="individual-course-modal-title">${curso.titulo}</h2>
        <p class="course-detail-description">${curso.descricaoCompleta}</p>
        <div class="course-detail-meta">
          <span><span class="material-symbols-rounded" aria-hidden="true">schedule</span>${curso.duracao}</span>
          <span><span class="material-symbols-rounded" aria-hidden="true">devices</span>${curso.formato}</span>
        </div>
        <div class="course-detail-section">
          <h3>Você vai aprender</h3>
          <ul>${curso.aprendizados.map((item) => `<li>${item}</li>`).join("")}</ul>
        </div>
        <div class="course-investment-card">
          <span>Investimento</span>
          <strong>${formatCoursePrice(curso)}</strong>
        </div>
        <div class="course-detail-actions">
          ${
            curso.available
              ? `<button class="course-detail-primary" type="button" data-course-buy="${curso.id}">Comprar curso</button>`
              : `<button class="course-detail-primary" type="button" disabled aria-disabled="true">Indisponível no momento</button>`
          }
          <a
            href="mailto:contato@evaglobal.com.br?subject=${encodeURIComponent(`Interesse no curso ${curso.titulo}`)}"
            class="course-detail-secondary"
          >Enviar e-mail</a>
        </div>
      </div>
    </div>
  `;
}

async function startCheckout(courseId, button) {
  if (checkoutInProgress) return;

  checkoutInProgress = true;
  const originalLabel = button.textContent;
  button.disabled = true;
  button.textContent = "Abrindo checkout...";

  try {
    const response = await fetch(CREATE_ORDER_API, {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ course: courseId }),
    });

    const data = await response.json().catch(() => null);

    if (response.status === 401) {
      sessionStorage.setItem("eva_pending_course", courseId);
      window.location.assign("./acesso.html");
      return;
    }

    if (!response.ok || data?.success !== true) {
      throw new Error(
        data?.message || "Não foi possível iniciar o pagamento.",
      );
    }

    if (
      typeof data.checkout_url !== "string" ||
      !data.checkout_url.startsWith("https://")
    ) {
      throw new Error("O checkout retornou uma URL inválida.");
    }

    window.location.assign(data.checkout_url);
  } catch (error) {
    console.error("Falha ao iniciar Checkout Pro:", error);
    window.alert(
      error instanceof Error
        ? error.message
        : "Não foi possível iniciar o pagamento.",
    );
    button.disabled = false;
    button.textContent = originalLabel;
    checkoutInProgress = false;
  }
}

function openModal(courseId) {
  if (!modal) return;

  const curso = cursosIndividuais.find((item) => item.id === courseId);
  if (!curso) return;

  individualModalLastFocusedElement = document.activeElement;
  renderModal(curso);
  modal.classList.add("is-open");
  modal.setAttribute("aria-hidden", "false");
  document.body.classList.add("course-modal-open");
  modal.querySelector(".course-modal-close")?.focus();
}

function closeModal() {
  if (!modal || checkoutInProgress) return;

  modal.classList.remove("is-open");
  modal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("course-modal-open");
  individualModalLastFocusedElement?.focus?.();
}

function getVisibleCards() {
  if (window.innerWidth <= 650) return 1;
  if (window.innerWidth <= 1000) return 2;
  return 3;
}

function updateCarousel() {
  if (!track) return;

  const cards = track.querySelectorAll(".resource-card");
  if (!cards.length) return;

  const visibleCards = getVisibleCards();
  const cardWidth = cards[0].getBoundingClientRect().width;
  const gap = 24;
  const maximumIndex = Math.max(0, cards.length - visibleCards);

  currentIndex = Math.min(currentIndex, maximumIndex);
  track.style.transform = `translateX(-${currentIndex * (cardWidth + gap)}px)`;

  if (prevButton) prevButton.disabled = currentIndex === 0;
  if (nextButton) nextButton.disabled = currentIndex >= maximumIndex;
}

track?.addEventListener("click", (event) => {
  const detailsButton = event.target.closest("[data-course-id]");
  if (!detailsButton) return;
  openModal(detailsButton.dataset.courseId);
});

modal?.addEventListener("click", (event) => {
  const buyButton = event.target.closest("[data-course-buy]");

  if (buyButton) {
    startCheckout(buyButton.dataset.courseBuy, buyButton);
    return;
  }

  if (event.target.closest("[data-course-close]")) {
    closeModal();
  }
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && modal?.classList.contains("is-open")) {
    closeModal();
  }
});

prevButton?.addEventListener("click", () => {
  currentIndex = Math.max(0, currentIndex - 1);
  updateCarousel();
});

nextButton?.addEventListener("click", () => {
  const maximumIndex = Math.max(
    0,
    cursosIndividuais.length - getVisibleCards(),
  );
  currentIndex = Math.min(maximumIndex, currentIndex + 1);
  updateCarousel();
});

window.addEventListener("resize", updateCarousel);

async function initializeCourses() {
  try {
    await loadCommercialCatalog();
  } catch (error) {
    console.error("Não foi possível carregar o catálogo comercial:", error);

    cursosIndividuais.forEach((course) => {
      course.price = null;
      course.currency = null;
      course.available = false;
    });
  }

  renderCursos();
  updateCarousel();

  const resumeCourseId = new URLSearchParams(window.location.search).get(
    "resume_checkout",
  );

  if (resumeCourseId) {
    const cleanUrl = new URL(window.location.href);
    cleanUrl.searchParams.delete("resume_checkout");
    window.history.replaceState({}, "", cleanUrl);

    const course = cursosIndividuais.find(
      (item) => item.id === resumeCourseId && item.available === true,
    );

    if (course) {
      openModal(resumeCourseId);
      const buyButton = modal?.querySelector("[data-course-buy]");

      if (buyButton?.dataset.courseBuy === resumeCourseId) {
        startCheckout(resumeCourseId, buyButton);
      }
    }
  }
}

initializeCourses();
