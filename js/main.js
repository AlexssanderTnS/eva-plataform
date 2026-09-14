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

  if (event.shiftKey && document.activeElement === firstElement) {
    event.preventDefault();
    lastElement.focus();
    return;
  }

  if (!event.shiftKey && document.activeElement === lastElement) {
    event.preventDefault();
    firstElement.focus();
  }
}

function trapOpenCourseModalFocus(event) {
  if (event.key !== "Tab") {
    return;
  }

  const openModal = document.querySelector(".course-modal.is-open");

  if (!openModal) {
    return;
  }

  const focusableElements = Array.from(
    openModal.querySelectorAll(
      'a[href], button:not([disabled]), [tabindex]:not([tabindex="-1"])',
    ),
  ).filter((element) => !element.hasAttribute("hidden"));

  if (!focusableElements.length) {
    event.preventDefault();
    return;
  }

  const firstElement = focusableElements[0];
  const lastElement = focusableElements[focusableElements.length - 1];

  if (event.shiftKey && document.activeElement === firstElement) {
    event.preventDefault();
    lastElement.focus();
    return;
  }

  if (!event.shiftKey && document.activeElement === lastElement) {
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

  trapOpenCourseModalFocus(event);
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

const revealElements = document.querySelectorAll(".reveal");

function showElement(element) {
  element.classList.add("is-visible");
}

if ("IntersectionObserver" in window) {
  const revealObserver = new IntersectionObserver(
    (entries, observer) => {
      entries.forEach((entry) => {
        if (!entry.isIntersecting) {
          return;
        }

        showElement(entry.target);
        observer.unobserve(entry.target);
      });
    },
    {
      threshold: 0.16,
      rootMargin: "0px 0px -60px 0px",
    },
  );

  revealElements.forEach((element) => {
    revealObserver.observe(element);
  });
} else {
  revealElements.forEach((element) => {
    showElement(element);
  });
}

function enableCarouselSwipe() {
  const carousels = [
    {
      viewport: document.querySelector(".resources-viewport"),
      previousButton: document.querySelector(".resources-arrow-prev"),
      nextButton: document.querySelector(".resources-arrow-next"),
    },
    {
      viewport: document.querySelector(".business-courses-viewport"),
      previousButton: document.querySelector(".business-courses-prev"),
      nextButton: document.querySelector(".business-courses-next"),
    },
  ];

  carousels.forEach(({ viewport, previousButton, nextButton }) => {
    if (!viewport) {
      return;
    }

    let touchStartX = 0;
    let touchStartY = 0;
    let touchEndX = 0;
    let touchEndY = 0;

    viewport.addEventListener(
      "touchstart",
      (event) => {
        const touch = event.changedTouches[0];

        touchStartX = touch.clientX;
        touchStartY = touch.clientY;
        touchEndX = touch.clientX;
        touchEndY = touch.clientY;
      },
      { passive: true },
    );

    viewport.addEventListener(
      "touchmove",
      (event) => {
        const touch = event.changedTouches[0];

        touchEndX = touch.clientX;
        touchEndY = touch.clientY;
      },
      { passive: true },
    );

    viewport.addEventListener(
      "touchend",
      () => {
        const horizontalDistance = touchEndX - touchStartX;
        const verticalDistance = touchEndY - touchStartY;
        const minimumSwipeDistance = 45;
        const isHorizontalSwipe =
          Math.abs(horizontalDistance) > Math.abs(verticalDistance);

        if (
          !isHorizontalSwipe ||
          Math.abs(horizontalDistance) < minimumSwipeDistance
        ) {
          return;
        }

        if (horizontalDistance < 0) {
          if (!nextButton?.disabled) {
            nextButton?.click();
          }
          return;
        }

        if (!previousButton?.disabled) {
          previousButton?.click();
        }
      },
      { passive: true },
    );
  });
}

function restoreMobileCtaColors() {
  if (document.querySelector("#mobile-cta-visual-fix")) {
    return;
  }

  const style = document.createElement("style");
  style.id = "mobile-cta-visual-fix";
  style.textContent = `
    .mobile-menu-cta {
      cursor: pointer;
      box-shadow: 0 14px 34px rgba(255, 94, 72, 0.2);
    }

    .mobile-menu-drawer.is-open .mobile-menu-cta {
      opacity: 1;
    }

    .mobile-menu-cta:hover,
    .mobile-menu-cta:focus-visible {
      color: #fff;
      background: linear-gradient(160deg, #ff5e48, #f28322);
      box-shadow: 0 18px 38px rgba(255, 94, 72, 0.28);
    }
  `;

  document.head.appendChild(style);
}

enableCarouselSwipe();
restoreMobileCtaColors();

console.log("EVA carregada com sucesso!");


// =========================================
// Estado de autenticação na navegação
// =========================================

function createAuthenticatedNavbarContent(link, user, isMobile = false) {
  const firstName = String(user.first_name || "").trim();
  const lastName = String(user.last_name || "").trim();
  const displayName = firstName || "Minha conta";
  const initials = `${firstName.charAt(0)}${lastName.charAt(0)}`.toUpperCase() || "E";

  const avatar = document.createElement("span");
  avatar.className = "navbar-user-avatar";
  avatar.setAttribute("aria-hidden", "true");
  avatar.textContent = initials;

  const copy = document.createElement("span");
  copy.className = "navbar-user-copy";

  const label = document.createElement("small");
  label.className = "navbar-user-label";
  label.textContent = isMobile ? "Conectado como" : "Olá,";

  const name = document.createElement("strong");
  name.className = "navbar-user-name";
  name.textContent = displayName;

  copy.append(label, name);
  link.replaceChildren(avatar, copy);
  link.href = "./conta.html";
  link.classList.add("is-authenticated");
  link.setAttribute("aria-label", `Acessar a conta de ${displayName}`);
  link.setAttribute("title", "Ir para a Área do Aluno");
}

async function syncNavbarAuthenticationState() {
  const desktopLink = document.querySelector(".navbar > .navbar-cta");
  const mobileLink = document.querySelector(".mobile-menu-cta");

  if (!desktopLink && !mobileLink) {
    return;
  }

  try {
    const response = await fetch("./api/auth/me.php", {
      method: "GET",
      credentials: "same-origin",
      cache: "no-store",
      headers: {
        Accept: "application/json",
      },
    });

    if (!response.ok) {
      return;
    }

    const payload = await response.json();

    if (!payload.success || !payload.user) {
      return;
    }

    document.documentElement.classList.add("user-is-authenticated");

    if (desktopLink) {
      createAuthenticatedNavbarContent(desktopLink, payload.user);
    }

    if (mobileLink) {
      createAuthenticatedNavbarContent(mobileLink, payload.user, true);
    }
  } catch (error) {
    // A navegação pública continua disponível se a consulta de sessão falhar.
  }
}

syncNavbarAuthenticationState();
