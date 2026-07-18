
const openableCards = document.querySelectorAll(".openable-card");

function destacarCard(cardId) {
    const card = document.querySelector(cardId);
    
    if (!card) {
        return;
    }

    // Remove destaque de todos os cards
    openableCards.forEach((item) => {
        item.classList.remove("is-highlighted");
    });

    // Rola até o card
    card.scrollIntoView({
        behavior: "smooth",
        block: "center"
    });

    // Aplica e remove o destaque
    setTimeout(() => {
        card.classList.add("is-highlighted");

        setTimeout(() => {
            card.classList.remove("is-highlighted");
        }, 1100);
    }, 550);
}

// 2. EVENTOS DE NAVEGAÇÃO DOS CARDS
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

// 3. NEWSLETTER
const newsletterForm = document.querySelector(".newsletter-form");

if (newsletterForm) {
    newsletterForm.addEventListener("submit", (event) => {
        event.preventDefault();

        const input = newsletterForm.querySelector("input");
        const email = input?.value.trim();

        if (!email) {
            alert("Digite um e-mail para se inscrever.");
            input?.focus();
            return;
        }

        alert("Inscrição registrada! Você receberá novidades da EVA em breve.");
        input.value = "";
    });
}

// 4. SCROLL SUAVE PARA TODOS OS LINKS INTERNOS
document.querySelectorAll('a[href^="#"]').forEach(anchor => {
    anchor.addEventListener("click", function(e) {
        // Ignora se for um link especial (business/personal)
        if (this.classList.contains("open-business") || 
            this.classList.contains("open-personal")) {
            return;
        }

        const targetId = this.getAttribute("href");
        if (targetId === "#") return;

        const target = document.querySelector(targetId);
        if (target) {
            e.preventDefault();
            target.scrollIntoView({
                behavior: "smooth",
                block: "start"
            });
        }
    });
});

console.log("🚀 EVA carregada com sucesso!");