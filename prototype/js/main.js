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

function loadCourseUiStyles() {
  const hasCourseUi = document.querySelector(
    ".resources-carousel, .business-courses-carousel, .course-modal",
  );

  if (!hasCourseUi || document.querySelector('link[data-course-ui="true"]')) {
    return;
  }

  const stylesheet = document.createElement("link");
  stylesheet.rel = "stylesheet";
  stylesheet.href = "./css/course-ui.css";
  stylesheet.dataset.courseUi = "true";
  document.head.appendChild(stylesheet);
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

loadCourseUiStyles();
enableCarouselSwipe();

console.log("EVA carregada com sucesso!");
