const cursosEmpresas = [
  {
    id: "comunicacao-empatica-empresas",
    titulo: "Comunicação Empática e Escuta Ativa",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1521737711867-e3b97375f902?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Desenvolva uma comunicação mais clara, respeitosa e eficiente, fortalecendo a colaboração e as relações entre equipes.",
    descricaoCompleta:
      "Treinamento voltado ao desenvolvimento de escuta ativa, empatia e comunicação assertiva para reduzir ruídos, fortalecer relações e melhorar a colaboração no ambiente de trabalho.",
    aprendizados: [
      "Praticar escuta ativa em situações profissionais",
      "Reduzir ruídos e falhas de comunicação",
      "Desenvolver respostas mais empáticas",
      "Fortalecer conversas de alinhamento e feedback",
      "Melhorar a colaboração entre equipes",
    ],
  },
  {
    id: "inteligencia-regulacao-emocional",
    titulo: "Inteligência e Regulação Emocional",
    duracao: "45 Minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1497366811353-6870744d04b2?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Estratégias práticas para reconhecer, compreender e regular emoções diante de desafios, mudanças e situações de pressão.",
    descricaoCompleta:
      "Conteúdo prático para apoiar profissionais no reconhecimento das próprias emoções e na construção de respostas mais equilibradas diante de pressão, mudanças e desafios do cotidiano.",
    aprendizados: [
      "Reconhecer emoções e gatilhos no trabalho",
      "Compreender reações emocionais sob pressão",
      "Aplicar estratégias de autorregulação",
      "Aprimorar decisões em momentos desafiadores",
      "Promover mais equilíbrio no cotidiano profissional",
    ],
  },
  {
    id: "cnv-empresas",
    titulo: "Comunicação Não Violenta na Prática",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Princípios e práticas para reduzir conflitos, ampliar a empatia e construir relações profissionais mais saudáveis.",
    descricaoCompleta:
      "Treinamento sobre os princípios da Comunicação Não Violenta aplicados ao ambiente profissional, com foco em clareza, escuta, redução de conflitos e relações mais colaborativas.",
    aprendizados: [
      "Separar fatos de julgamentos",
      "Reconhecer necessidades em situações de conflito",
      "Fazer pedidos claros e possíveis",
      "Escutar colegas com mais empatia",
      "Conduzir conversas difíceis de forma construtiva",
    ],
  },
  {
    id: "habitos-saudaveis-trabalho",
    titulo: "Construção de Hábitos Saudáveis no Trabalho",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Estratégias para desenvolver organização, disciplina, responsabilidade e hábitos positivos no ambiente profissional.",
    descricaoCompleta:
      "Curso prático para apoiar a construção de rotinas mais sustentáveis, organização do trabalho e comportamentos que favoreçam produtividade, responsabilidade e colaboração.",
    aprendizados: [
      "Identificar hábitos que impactam o trabalho",
      "Transformar objetivos em ações menores",
      "Organizar melhor rotinas e prioridades",
      "Criar comportamentos mais sustentáveis",
      "Acompanhar progresso e manter consistência",
    ],
  },
  {
    id: "respeito-em-acao",
    titulo: "Respeito em Ação: Prevenção e Enfrentamento do Assédio no Trabalho",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Conscientização e prevenção para fortalecer ambientes de trabalho mais seguros, éticos, saudáveis e respeitosos.",
    descricaoCompleta:
      "Conteúdo de conscientização sobre assédio moral e sexual, seus impactos e formas de prevenção, com foco no fortalecimento de ambientes de trabalho mais seguros e respeitosos.",
    aprendizados: [
      "Reconhecer comportamentos inadequados",
      "Diferenciar conflitos de situações de assédio",
      "Compreender impactos individuais e organizacionais",
      "Conhecer atitudes preventivas no cotidiano",
      "Fortalecer uma cultura de respeito",
    ],
  },
  {
    id: "atendimento-excelencia",
    titulo: "Atendimento de Excelência: Estratégias Práticas para Profissionais Modernos",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1556761175-b413da4baf72?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Atendimento humanizado, comunicação eficaz e resolução de problemas para proporcionar experiências melhores aos clientes.",
    descricaoCompleta:
      "Treinamento voltado ao desenvolvimento de uma experiência de atendimento mais clara, humana e eficiente, fortalecendo comunicação, postura e resolução de problemas.",
    aprendizados: [
      "Aprimorar postura profissional no atendimento",
      "Compreender necessidades do cliente",
      "Comunicar informações com clareza",
      "Lidar melhor com situações difíceis",
      "Fortalecer a experiência e a confiança do cliente",
    ],
  },
  {
    id: "lideranca-consciente",
    titulo: "Liderança Consciente: Influência e Desenvolvimento de Equipes",
    duracao: "1 Hora",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Competências de comunicação, gestão de pessoas, inteligência emocional e tomada de decisão para formar lideranças mais preparadas.",
    descricaoCompleta:
      "Treinamento para lideranças que desejam desenvolver comunicação, gestão de pessoas, inteligência emocional e tomada de decisão de forma mais consciente e responsável.",
    aprendizados: [
      "Aprimorar comunicação de liderança",
      "Conduzir equipes com mais clareza",
      "Desenvolver escuta e feedback",
      "Tomar decisões de forma mais consciente",
      "Fortalecer engajamento e desenvolvimento da equipe",
    ],
  },
  {
    id: "consciencia-financeira",
    titulo: "Consciência Financeira: Construindo Hábitos Sustentáveis para o Bem-Estar",
    duracao: "45 Minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Planejamento financeiro e hábitos sustentáveis para promover mais organização, tranquilidade e bem-estar no cotidiano.",
    descricaoCompleta:
      "Curso de educação financeira aplicada ao cotidiano, com estratégias para organização de gastos, planejamento e desenvolvimento de hábitos mais sustentáveis.",
    aprendizados: [
      "Compreender melhor receitas e despesas",
      "Organizar o orçamento pessoal",
      "Identificar hábitos financeiros prejudiciais",
      "Definir metas mais realistas",
      "Construir uma relação mais saudável com o dinheiro",
    ],
  },
  {
    id: "vistoria-imovel",
    titulo: "A Importância da Vistoria do Imóvel",
    duracao: "30 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Práticas para realizar inspeções com organização, precisão e segurança, reduzindo riscos e conflitos futuros.",
    descricaoCompleta:
      "Treinamento para profissionais que atuam com vistorias, reforçando organização, atenção aos detalhes, registro de informações e padronização do processo.",
    aprendizados: [
      "Compreender a importância da vistoria",
      "Organizar etapas de inspeção",
      "Registrar informações com mais precisão",
      "Identificar pontos que exigem atenção",
      "Reduzir riscos e conflitos posteriores",
    ],
  },
  {
    id: "mostradores-imoveis",
    titulo: "O Impacto dos Mostradores de Imóveis na Experiência do Cliente",
    duracao: "30 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Técnicas de atendimento, apresentação de imóveis e postura profissional para tornar visitas mais eficientes e positivas.",
    descricaoCompleta:
      "Treinamento para profissionais responsáveis pela apresentação de imóveis, com foco em atendimento, comunicação, organização da visita e experiência do cliente.",
    aprendizados: [
      "Preparar melhor a experiência de visita",
      "Apresentar informações com clareza",
      "Adotar postura profissional adequada",
      "Compreender expectativas do cliente",
      "Conduzir visitas de forma mais organizada",
    ],
  },
];

const businessTrack = document.querySelector("#business-courses-track");
const businessPrevButton = document.querySelector(".business-courses-prev");
const businessNextButton = document.querySelector(".business-courses-next");
const businessModal = document.querySelector("#business-course-modal");
const businessModalContent = document.querySelector("#business-course-modal-content");
let businessCurrentIndex = 0;
let businessLastFocusedElement = null;

function renderBusinessCourses() {
  if (!businessTrack) return;

  businessTrack.innerHTML = cursosEmpresas
    .map(
      (curso) => `
        <article class="business-course-card">
          <div class="business-course-visual">
            <img src="${curso.imagem}" alt="${curso.titulo}" loading="lazy" />
            <span class="business-course-type">Treinamento corporativo</span>
          </div>

          <div class="business-course-content">
            <h3>${curso.titulo}</h3>
            <p>${curso.descricao}</p>

            <div class="business-course-meta">
              <span class="material-symbols-rounded" aria-hidden="true">schedule</span>
              <span>${curso.duracao}</span>
              <span class="material-symbols-rounded" aria-hidden="true">devices</span>
              <span>${curso.formato}</span>
            </div>

            <button class="business-course-button" type="button" data-course-id="${curso.id}">
              Ver detalhes
              <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>
            </button>
          </div>
        </article>
      `,
    )
    .join("");
}

function renderBusinessModal(curso) {
  if (!businessModalContent) return;

  businessModalContent.innerHTML = `
    <div class="course-detail-layout">
      <div class="course-detail-visual">
        <img src="${curso.imagem}" alt="${curso.titulo}" />
      </div>

      <div class="course-detail-main">
        <span class="course-detail-type">Treinamento corporativo</span>
        <h2 id="business-course-modal-title">${curso.titulo}</h2>
        <p class="course-detail-description">${curso.descricaoCompleta}</p>

        <div class="course-detail-meta">
          <span><span class="material-symbols-rounded" aria-hidden="true">schedule</span>${curso.duracao}</span>
          <span><span class="material-symbols-rounded" aria-hidden="true">devices</span>${curso.formato}</span>
        </div>

        <div class="course-detail-section">
          <h3>O que o treinamento desenvolve</h3>
          <ul>${curso.aprendizados.map((item) => `<li>${item}</li>`).join("")}</ul>
        </div>

        <div class="course-proposal-card">
          <span>Contratação corporativa</span>
          <strong>Proposta personalizada</strong>
          <small>Formato, aplicação e condições definidos conforme o contexto da organização.</small>
        </div>

        <div class="course-detail-actions">
          <a href="./contato.html?curso=${encodeURIComponent(curso.id)}" class="course-detail-primary">Solicitar proposta</a>
          <a href="mailto:contato@evaglobal.com.br?subject=${encodeURIComponent(`Interesse no treinamento ${curso.titulo}`)}" class="course-detail-secondary">Enviar e-mail</a>
        </div>
      </div>
    </div>
  `;
}

function openBusinessModal(courseId) {
  if (!businessModal) return;
  const curso = cursosEmpresas.find((item) => item.id === courseId);
  if (!curso) return;

  businessLastFocusedElement = document.activeElement;
  renderBusinessModal(curso);
  businessModal.classList.add("is-open");
  businessModal.setAttribute("aria-hidden", "false");
  document.body.classList.add("course-modal-open");
  businessModal.querySelector(".course-modal-close")?.focus();
}

function closeBusinessModal() {
  if (!businessModal) return;
  businessModal.classList.remove("is-open");
  businessModal.setAttribute("aria-hidden", "true");
  document.body.classList.remove("course-modal-open");
  businessLastFocusedElement?.focus?.();
}

function getBusinessVisibleCards() {
  if (window.innerWidth <= 720) return 1;
  if (window.innerWidth <= 1060) return 2;
  return 3;
}

function updateBusinessCarousel() {
  if (!businessTrack) return;
  const cards = businessTrack.querySelectorAll(".business-course-card");
  if (!cards.length) return;

  const visibleCards = getBusinessVisibleCards();
  const cardWidth = cards[0].getBoundingClientRect().width;
  const gap = 24;
  const maximumIndex = Math.max(0, cards.length - visibleCards);

  businessCurrentIndex = Math.min(businessCurrentIndex, maximumIndex);
  businessTrack.style.transform = `translateX(-${businessCurrentIndex * (cardWidth + gap)}px)`;

  if (businessPrevButton) businessPrevButton.disabled = businessCurrentIndex === 0;
  if (businessNextButton) businessNextButton.disabled = businessCurrentIndex >= maximumIndex;
}

businessTrack?.addEventListener("click", (event) => {
  const button = event.target.closest("[data-course-id]");
  if (!button) return;
  openBusinessModal(button.dataset.courseId);
});

businessModal?.addEventListener("click", (event) => {
  if (event.target.closest("[data-course-close]")) closeBusinessModal();
});

document.addEventListener("keydown", (event) => {
  if (event.key === "Escape" && businessModal?.classList.contains("is-open")) {
    closeBusinessModal();
  }
});

businessPrevButton?.addEventListener("click", () => {
  businessCurrentIndex = Math.max(0, businessCurrentIndex - 1);
  updateBusinessCarousel();
});

businessNextButton?.addEventListener("click", () => {
  const maximumIndex = Math.max(0, cursosEmpresas.length - getBusinessVisibleCards());
  businessCurrentIndex = Math.min(maximumIndex, businessCurrentIndex + 1);
  updateBusinessCarousel();
});

window.addEventListener("resize", updateBusinessCarousel);

renderBusinessCourses();
updateBusinessCarousel();
