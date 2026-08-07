    const cursosIndividuais = [
    {
        titulo: "Gestão Financeira Pessoal",
        duracao: "45 minutos",
        formato: "Online • Moodle",
        imagem: "./assets/images/gestaoFinanceira.png",
        descricao:
        "Estratégias práticas para organizar o orçamento, controlar despesas, definir metas e desenvolver hábitos financeiros mais saudáveis.",
    },
    {
        titulo:
        "Micro-Hábitos Pessoais: Construindo Mudanças Sustentáveis no Dia a Dia",
        duracao: "45 minutos",
        formato: "Online • Moodle",
        imagem: "./assets/images/microPessoais.png",
        descricao:
        "Estratégias práticas para criar e manter hábitos positivos, fortalecer a disciplina e alcançar objetivos de forma consistente.",
    },
    {
        titulo:
        "Comunicação Não Violenta na Prática: Transformando Relações Pessoais e Profissionais",
        duracao: "45 minutos",
        formato: "Online • Moodle",
        imagem: "./assets/images/comunicacaoNviolenta.png",
        descricao:
        "Aprenda a expressar suas necessidades com clareza, lidar melhor com conflitos e construir relações mais conscientes e respeitosas.",
    },
    {
        titulo: "Regulação Emocional",
        duracao: "45 minutos",
        formato: "Online • Moodle",
        imagem: "./assets/images/regulacaoEmocional.png",
        descricao:
        "Estratégias práticas para compreender e gerenciar emoções, lidar com situações de estresse e tomar decisões de maneira mais equilibrada.",
    },
    {
        titulo: "Comunicação Empática e Escuta Ativa",
        duracao: "45 minutos",
        formato: "Online • Moodle",
        imagem: "./assets/images/comunicacaoEmpatica.png",
        descricao:
        "Desenvolva uma comunicação mais clara e respeitosa por meio de técnicas de escuta ativa, empatia e comunicação assertiva.",
    },
    ];

    const track = document.querySelector("#resources-track");
    const prevButton = document.querySelector(".resources-arrow-prev");
    const nextButton = document.querySelector(".resources-arrow-next");

    let currentIndex = 0;

    function renderCursos() {
    if (!track) return;

    track.innerHTML = cursosIndividuais
        .map(
        (curso) => `
            <article class="resource-card">
            <div class="resource-card-visual">
                <img
                src="${curso.imagem}"
                alt="${curso.titulo}"
                />

                <span class="resource-type">
                Curso online
                </span>
            </div>

            <div class="resource-card-content">
                <h3>${curso.titulo}</h3>

                <p>${curso.descricao}</p>

                <div class="resource-meta">
                <span class="material-symbols-rounded">
                    schedule
                </span>

                <span>${curso.duracao}</span>

                <span class="material-symbols-rounded">
                    devices
                </span>

                <span>${curso.formato}</span>
                </div>

                <a
                href="./contato.html"
                class="resource-button"
                >
                Tenho interesse

                <span class="material-symbols-rounded">
                    arrow_forward
                </span>
                </a>
            </div>
            </article>
        `,
        )
        .join("");
    }

    function getVisibleCards() {
    if (window.innerWidth <= 650) {
        return 1;
    }

    if (window.innerWidth <= 1000) {
        return 2;
    }

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

    const offset = currentIndex * (cardWidth + gap);

    track.style.transform = `translateX(-${offset}px)`;

    if (prevButton) {
        prevButton.disabled = currentIndex === 0;
    }

    if (nextButton) {
        nextButton.disabled = currentIndex >= maximumIndex;
    }
    }

    prevButton?.addEventListener("click", () => {
    currentIndex--;

    if (currentIndex < 0) {
        currentIndex = 0;
    }

    updateCarousel();
    });

    nextButton?.addEventListener("click", () => {
    const visibleCards = getVisibleCards();

    const maximumIndex = Math.max(0, cursosIndividuais.length - visibleCards);

    currentIndex++;

    if (currentIndex > maximumIndex) {
        currentIndex = maximumIndex;
    }

    updateCarousel();
    });

    window.addEventListener("resize", () => {
    updateCarousel();
    });

    renderCursos();
    updateCarousel();
