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
    block: "center",
  });

  setTimeout(() => {
    card.classList.add("is-highlighted");

    setTimeout(() => {
      card.classList.remove("is-highlighted");
    }, 1100);
  }, 550);
}

const menuToggle = document.querySelector(".mobile-menu-toggle");
const menuToggleIcon = menuToggle?.querySelector(
  ".material-symbols-rounded",
);
const menuDrawer = document.querySelector(".mobile-menu-drawer");
const menuOverlay = document.querySelector(".mobile-menu-overlay");
const menuCloseButtons = document.querySelectorAll("[data-menu-close]");
const menuLinks = document.querySelectorAll("[data-menu-link]");

let lastFocusedElement = null;

function getFocusableMenuElements() {
  if (!menuDrawer) {
    return [];
  }

  return Array.from(
    menuDrawer.querySelectorAll(
      'a[href]:not([aria-disabled="true"]), button:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ),
  );
}

function openMobileMenu() {
  if (!menuToggle || !menuDrawer || !menuOverlay) {
    return;
  }

  lastFocusedElement = document.activeElement;

  menuDrawer.inert = false;

  menuToggle.setAttribute("aria-expanded", "true");
  menuToggle.setAttribute("aria-label", "Fechar menu");

  if (menuToggleIcon) {
    menuToggleIcon.textContent = "close";
  }

  menuDrawer.setAttribute("aria-hidden", "false");
  menuOverlay.setAttribute("aria-hidden", "false");

  menuDrawer.classList.add("is-open");
  menuOverlay.classList.add("is-open");

  document.body.classList.add("menu-open");

  const focusableElements = getFocusableMenuElements();
  focusableElements[0]?.focus();
}

function closeMobileMenu(restoreFocus = true) {
  if (!menuToggle || !menuDrawer || !menuOverlay) {
    return;
  }

  const focusIsInsideMenu = menuDrawer.contains(document.activeElement);

  if (focusIsInsideMenu) {
    menuToggle.focus();
  }

  menuDrawer.inert = true;

  menuToggle.setAttribute("aria-expanded", "false");
  menuToggle.setAttribute("aria-label", "Abrir menu");

  if (menuToggleIcon) {
    menuToggleIcon.textContent = "menu";
  }

  menuDrawer.setAttribute("aria-hidden", "true");
  menuOverlay.setAttribute("aria-hidden", "true");

  menuDrawer.classList.remove("is-open");
  menuOverlay.classList.remove("is-open");

  document.body.classList.remove("menu-open");

  if (restoreFocus && lastFocusedElement instanceof HTMLElement) {
    lastFocusedElement.focus();
  }
}

function trapFocus(event) {
  if (
    event.key !== "Tab" ||
    !menuDrawer?.classList.contains("is-open")
  ) {
    return;
  }

  const focusableElements = getFocusableMenuElements();

  if (!focusableElements.length) {
    event.preventDefault();
    return;
  }

  const firstElement = focusableElements[0];
  const lastElement = focusableElements[focusableElements.length - 1];

  if (
    event.shiftKey &&
    document.activeElement === firstElement
  ) {
    event.preventDefault();
    lastElement.focus();
    return;
  }

  if (
    !event.shiftKey &&
    document.activeElement === lastElement
  ) {
    event.preventDefault();
    firstElement.focus();
  }
}

menuToggle?.addEventListener("click", () => {
  const isOpen = menuDrawer?.classList.contains("is-open");

  if (isOpen) {
    closeMobileMenu();
    return;
  }

  openMobileMenu();
});

menuCloseButtons.forEach((button) => {
  button.addEventListener("click", () => {
    closeMobileMenu();
  });
});

menuLinks.forEach((link) => {
  link.addEventListener("click", () => {
    closeMobileMenu(false);
  });
});

document.addEventListener("keydown", (event) => {
  if (
    event.key === "Escape" &&
    menuDrawer?.classList.contains("is-open")
  ) {
    closeMobileMenu();
    return;
  }

  trapFocus(event);
});

window.addEventListener("resize", () => {
  if (
    window.innerWidth > 1060 &&
    menuDrawer?.classList.contains("is-open")
  ) {
    closeMobileMenu(false);
  }
});

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
      input?.focus();
      return;
    }

    alert("Formulário em fase de configuração.");
    input.value = "";
  });
}

document.querySelectorAll('a[href^="#"]').forEach((anchor) => {
  anchor.addEventListener("click", function (event) {
    if (
      this.classList.contains("open-business") ||
      this.classList.contains("open-personal")
    ) {
      return;
    }

    const targetId = this.getAttribute("href");

    if (!targetId || targetId === "#") {
      return;
    }

    const target = document.querySelector(targetId);

    if (target) {
      event.preventDefault();

      target.scrollIntoView({
        behavior: "smooth",
        block: "start",
      });
    }
  });
});

console.log("EVA carregada com sucesso!");