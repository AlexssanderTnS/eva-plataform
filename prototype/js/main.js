const flipCards = document.querySelectorAll(".flip-card");

flipCards.forEach((card) => {
    card.addEventListener("click", (event) => {
        const clickedLink = event.target.closest("a");

        if (clickedLink) {
            return;
        }

        card.classList.toggle("is-flipped");
    });
});

function abrirCardAutomaticamente(cardId) {
    const card = document.querySelector(cardId);

    if (!card) {
        return;
    }

    document.querySelectorAll(".flip-card").forEach((item) => {
        item.classList.remove("is-flipped", "is-highlighted");
    });

    card.scrollIntoView({
        behavior: "smooth",
        block: "center"
    });

    setTimeout(() => {
        card.classList.add("is-flipped", "is-highlighted");

        setTimeout(() => {
            card.classList.remove("is-highlighted");
        }, 900);
    }, 650);
}

const linksEmpresas = document.querySelectorAll(".open-business");
const linksPessoal = document.querySelectorAll(".open-personal");

linksEmpresas.forEach((link) => {
    link.addEventListener("click", (event) => {
        event.preventDefault();
        abrirCardAutomaticamente("#business");
    });
});

linksPessoal.forEach((link) => {
    link.addEventListener("click", (event) => {
        event.preventDefault();
        abrirCardAutomaticamente("#for-you");
    });
});