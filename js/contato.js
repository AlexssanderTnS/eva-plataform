"use strict";

document.addEventListener("DOMContentLoaded", () => {
  const form = document.querySelector("#contact-form");

  if (!form) {
    return;
  }

  const submitButton = form.querySelector(".submit-button");
  const formMessage = document.querySelector("#form-message");

  const phoneField = document.querySelector("#phone");
  const messageField = document.querySelector("#message");
  const characterCounter = document.querySelector("#character-counter");

  const privacyField = document.querySelector("#privacy");
  const privacyError = document.querySelector("#privacy-error");

  const MAX_MESSAGE_LENGTH = 1000;
  const formStartedAt = Date.now();

  function formatPhone(value) {
    const numbers = value.replace(/\D/g, "").slice(0, 11);

    if (numbers.length <= 2) {
      return numbers;
    }

    if (numbers.length <= 6) {
      return `(${numbers.slice(0, 2)}) ${numbers.slice(2)}`;
    }

    if (numbers.length <= 10) {
      return `(${numbers.slice(0, 2)}) ${numbers.slice(2, 6)}-${numbers.slice(6)}`;
    }

    return `(${numbers.slice(0, 2)}) ${numbers.slice(2, 7)}-${numbers.slice(7)}`;
  }

  function updateCharacterCounter() {
    if (!messageField || !characterCounter) {
      return;
    }

    characterCounter.textContent =
      `${messageField.value.length} / ${MAX_MESSAGE_LENGTH}`;
  }

  function getFormGroup(field) {
    return field.closest(".form-group");
  }

  function showFieldError(field, message) {
    const group = getFormGroup(field);

    if (!group) {
      return;
    }

    group.classList.add("has-error");

    const errorElement = group.querySelector(".field-error");

    if (errorElement) {
      errorElement.textContent = message;
    }

    field.setAttribute("aria-invalid", "true");
  }

  function clearFieldError(field) {
    const group = getFormGroup(field);

    if (!group) {
      return;
    }

    group.classList.remove("has-error");

    const errorElement = group.querySelector(".field-error");

    if (errorElement) {
      errorElement.textContent = "";
    }

    field.removeAttribute("aria-invalid");
  }

  function isValidEmail(email) {
    return /^[^\s@]+@[^\s@]+\.[^\s@]{2,}$/.test(email);
  }

  function validateField(field) {
    const value = field.value.trim();

    clearFieldError(field);

    if (field.required && !value) {
      showFieldError(field, "Este campo é obrigatório.");
      return false;
    }

    if (field.type === "email" && value && !isValidEmail(value)) {
      showFieldError(field, "Digite um endereço de e-mail válido.");
      return false;
    }

    if (field.id === "name" && value.length > 0 && value.length < 3) {
      showFieldError(field, "Digite seu nome completo.");
      return false;
    }

    if (
      field.id === "phone" &&
      value &&
      value.replace(/\D/g, "").length < 10
    ) {
      showFieldError(field, "Digite um telefone válido com DDD.");
      return false;
    }

    if (
      field.id === "message" &&
      value.length > 0 &&
      value.length < 20
    ) {
      showFieldError(
        field,
        "Escreva uma mensagem com pelo menos 20 caracteres."
      );
      return false;
    }

    return true;
  }

  function validatePrivacy() {
    if (!privacyField) {
      return true;
    }

    if (!privacyField.checked) {
      if (privacyError) {
        privacyError.textContent =
          "Você precisa concordar com a Política de Privacidade.";
      }

      return false;
    }

    if (privacyError) {
      privacyError.textContent = "";
    }

    return true;
  }

  function validateForm() {
    const fields = form.querySelectorAll(
      'input:not([type="radio"]):not([type="checkbox"]), select, textarea'
    );

    let isValid = true;

    fields.forEach((field) => {
      if (!validateField(field)) {
        isValid = false;
      }
    });

    if (!validatePrivacy()) {
      isValid = false;
    }

    return isValid;
  }

  function showFormMessage(type, message) {
    if (!formMessage) {
      return;
    }

    formMessage.className = `form-message ${type}`;
    formMessage.textContent = message;
    formMessage.scrollIntoView({ behavior: "smooth", block: "nearest" });
  }

  function clearFormMessage() {
    if (!formMessage) {
      return;
    }

    formMessage.className = "form-message";
    formMessage.textContent = "";
  }

  function setLoading(isLoading) {
    if (!submitButton) {
      return;
    }

    submitButton.disabled = isLoading;
    submitButton.classList.toggle("loading", isLoading);
  }

  function clearValidationState() {
    form.querySelectorAll(".form-group").forEach((group) => {
      group.classList.remove("has-error");
    });

    form.querySelectorAll(".field-error").forEach((error) => {
      error.textContent = "";
    });

    form.querySelectorAll('[aria-invalid="true"]').forEach((field) => {
      field.removeAttribute("aria-invalid");
    });

    if (privacyError) {
      privacyError.textContent = "";
    }
  }

  function applyServerErrors(errors) {
    if (!errors || typeof errors !== "object") {
      return;
    }

    Object.entries(errors).forEach(([fieldName, message]) => {
      if (fieldName === "privacy") {
        if (privacyError) {
          privacyError.textContent = String(message);
        }
        return;
      }

      const field = form.elements.namedItem(fieldName);

      if (field instanceof HTMLElement && "value" in field) {
        showFieldError(field, String(message));
      }
    });
  }

  phoneField?.addEventListener("input", (event) => {
    event.target.value = formatPhone(event.target.value);
  });

  messageField?.addEventListener("input", updateCharacterCounter);

  privacyField?.addEventListener("change", () => {
    if (privacyField.checked && privacyError) {
      privacyError.textContent = "";
    }
  });

  form.querySelectorAll("input, select, textarea").forEach((field) => {
    if (field.type === "radio" || field.type === "checkbox") {
      return;
    }

    field.addEventListener("blur", () => {
      validateField(field);
    });

    field.addEventListener("input", () => {
      const group = getFormGroup(field);

      if (group?.classList.contains("has-error")) {
        validateField(field);
      }
    });
  });

form.addEventListener("submit", async (event) => {
  event.preventDefault();

  clearFormMessage();

  if (!validateForm()) {
    showFormMessage(
      "error",
      "Verifique os campos destacados antes de continuar."
    );

    const firstInvalidField =
      form.querySelector('[aria-invalid="true"]');

    firstInvalidField?.focus();

    return;
  }

  setLoading(true);

  try {
    const formData = new FormData(form);

    const profileField = form.querySelector(
      'input[name="profile"]:checked'
    );

    const payload = {
      name: String(
        formData.get("name") || ""
      ).trim(),

      email: String(
        formData.get("email") || ""
      ).trim(),

      phone: String(
        formData.get("phone") || ""
      ).trim(),

      company: String(
        formData.get("company") || ""
      ).trim(),

      profile: profileField?.value || "",

      subject: String(
        formData.get("subject") || ""
      ).trim(),

      message: String(
        formData.get("message") || ""
      ).trim(),

      privacy: Boolean(
        privacyField?.checked
      ),

      website: String(
        formData.get("website") || ""
      ).trim(),

      form_started_at: formStartedAt
    };

    const response = await fetch(
      "./api/contato.php",
      {
        method: "POST",

        headers: {
          "Content-Type": "application/json",
          Accept: "application/json"
        },

        body: JSON.stringify(payload)
      }
    );

    let result;

    try {
      result = await response.json();
    } catch {
      throw new Error(
        "O servidor retornou uma resposta inválida."
      );
    }

    if (!response.ok || !result?.success) {
      applyServerErrors(result?.errors);

      throw new Error(
        result?.message ||
        "Não foi possível enviar sua mensagem."
      );
    }

    showFormMessage(
      "success",
      result.message ||
      "Mensagem enviada com sucesso. A equipe da EVA entrará em contato em breve."
    );

    form.reset();

    clearValidationState();
    updateCharacterCounter();

  } catch (error) {
    console.error(
      "Erro ao enviar formulário:",
      error
    );

    showFormMessage(
      "error",
      error instanceof Error
        ? error.message
        : "Não foi possível enviar sua mensagem. Tente novamente."
    );

  } finally {
    setLoading(false);
  }
});

  updateCharacterCounter();
});
