const openableCards = document.querySelectorAll(".openable-card");

function destacarCard(cardId) {
    const card = document.querySelector(cardId);

    if (!card) {
        return;
    }

    openableCards.forEach((item) => {
        item.classList.remove("is-highlighted");
    });

    card.scrollIntoView({
        behavior: "smooth",
        block: "center"
    });

    setTimeout(() => {
        card.classList.add("is-highlighted");

        setTimeout(() => {
            card.classList.remove("is-highlighted");
        }, 1100);
    }, 550);
}

document.querySelectorAll(".open-business").forEach((link) => {
    link.addEventListener("click", (event) => {
        event.preventDefault();
        destacarCard("#business");
    });
});

document.querySelectorAll(".open-personal").forEach((link) => {
    link.addEventListener("click", (event) => {
        event.preventDefault();
        destacarCard("#for-you");
    });
});

const newsletterForm = document.querySelector(".newsletter-form");

if (newsletterForm) {
    newsletterForm.addEventListener("submit", (event) => {
        event.preventDefault();

        const input = newsletterForm.querySelector("input");
        const email = input?.value.trim();

        if (!email) {
            alert("Digite um e-mail para se inscrever.");
            return;
        }

        alert("Inscrição registrada para a demonstração da EVA.");
        input.value = "";
    });
}