const yearElement = document.querySelector("#current-year");

if (yearElement) {
  yearElement.textContent = new Date().getFullYear();
}

const visual = document.querySelector(".hero__visual");

if (visual && window.matchMedia("(pointer: fine)").matches) {
  visual.addEventListener("pointermove", (event) => {
    const bounds = visual.getBoundingClientRect();
    const x = (event.clientX - bounds.left) / bounds.width - 0.5;
    const y = (event.clientY - bounds.top) / bounds.height - 0.5;

    visual.style.setProperty("--mouse-x", `${x * 8}px`);
    visual.style.setProperty("--mouse-y", `${y * 8}px`);

    const card = visual.querySelector(".visual-card");
    if (card) {
      card.style.transform = `translate(${x * 8}px, ${y * 8}px) rotate(${5 + x * 2}deg)`;
    }
  });

  visual.addEventListener("pointerleave", () => {
    const card = visual.querySelector(".visual-card");
    if (card) {
      card.style.transform = "rotate(5deg)";
    }
  });
}