const cursosEmpresas = [
  {
    titulo: "Comunicação Empática e Escuta Ativa",
    duracao: "45 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1521737711867-e3b97375f902?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Desenvolva uma comunicação mais clara, respeitosa e eficiente, fortalecendo a colaboração e as relações entre equipes.",
  },
  {
    titulo: "Inteligência e Regulação Emocional",
    duracao: "35 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1497366811353-6870744d04b2?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Estratégias práticas para reconhecer, compreender e regular emoções diante de desafios, mudanças e situações de pressão.",
  },
  {
    titulo: "Comunicação Não Violenta na Prática",
    duracao: "45 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1551836022-d5d88e9218df?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Princípios e práticas para reduzir conflitos, ampliar a empatia e construir relações profissionais mais saudáveis.",
  },
  {
    titulo: "Construção de Hábitos Saudáveis no Trabalho",
    duracao: "45 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1524758631624-e2822e304c36?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Estratégias para desenvolver organização, disciplina, responsabilidade e hábitos positivos no ambiente profissional.",
  },
  {
    titulo: "Respeito em Ação: Prevenção e Enfrentamento do Assédio no Trabalho",
    duracao: "45 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1522071820081-009f0129c71c?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Conscientização e prevenção para fortalecer ambientes de trabalho mais seguros, éticos, saudáveis e respeitosos.",
  },
  {
    titulo: "Atendimento de Excelência: Estratégias Práticas para Profissionais Modernos",
    duracao: "45 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1556761175-b413da4baf72?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Atendimento humanizado, comunicação eficaz e resolução de problemas para proporcionar experiências melhores aos clientes.",
  },
  {
    titulo: "Liderança Consciente: Influência e Desenvolvimento de Equipes",
    duracao: "45 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1517245386807-bb43f82c33c4?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Competências de comunicação, gestão de pessoas, inteligência emocional e tomada de decisão para formar lideranças mais preparadas.",
  },
  {
    titulo: "Consciência Financeira: Construindo Hábitos Sustentáveis para o Bem-Estar",
    duracao: "35 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1554224155-8d04cb21cd6c?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Planejamento financeiro e hábitos sustentáveis para promover mais organização, tranquilidade e bem-estar no cotidiano.",
  },
  {
    titulo: "A Importância da Vistoria do Imóvel",
    duracao: "30 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1560518883-ce09059eeffa?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Práticas para realizar inspeções com organização, precisão e segurança, reduzindo riscos e conflitos futuros.",
  },
  {
    titulo: "O Impacto dos Mostradores de Imóveis na Experiência do Cliente",
    duracao: "30 minutos",
    formato: "Online • Moodle",
    imagem: "https://images.unsplash.com/photo-1560250097-0b93528c311a?auto=format&fit=crop&w=900&q=80",
    descricao:
      "Técnicas de atendimento, apresentação de imóveis e postura profissional para tornar visitas mais eficientes e positivas.",
  },
];

const businessTrack = document.querySelector("#business-courses-track");
const businessPrevButton = document.querySelector(".business-courses-prev");
const businessNextButton = document.querySelector(".business-courses-next");

let businessCurrentIndex = 0;

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

            <a href="./contato.html" class="business-course-button">
              Tenho interesse
              <span class="material-symbols-rounded" aria-hidden="true">arrow_forward</span>
            </a>
          </div>
        </article>
      `,
    )
    .join("");
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

  const offset = businessCurrentIndex * (cardWidth + gap);
  businessTrack.style.transform = `translateX(-${offset}px)`;

  if (businessPrevButton) {
    businessPrevButton.disabled = businessCurrentIndex === 0;
  }

  if (businessNextButton) {
    businessNextButton.disabled = businessCurrentIndex >= maximumIndex;
  }
}

businessPrevButton?.addEventListener("click", () => {
  businessCurrentIndex = Math.max(0, businessCurrentIndex - 1);
  updateBusinessCarousel();
});

businessNextButton?.addEventListener("click", () => {
  const maximumIndex = Math.max(
    0,
    cursosEmpresas.length - getBusinessVisibleCards(),
  );

  businessCurrentIndex = Math.min(maximumIndex, businessCurrentIndex + 1);
  updateBusinessCarousel();
});

window.addEventListener("resize", updateBusinessCarousel);

renderBusinessCourses();
updateBusinessCarousel();
