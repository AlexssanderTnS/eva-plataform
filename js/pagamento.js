const card = document.querySelector("#payment-status-card");
const title = document.querySelector("#payment-status-title");
const message = document.querySelector("#payment-status-message");
const icon = document.querySelector("#payment-status-icon");
const details = document.querySelector("#payment-details");
const course = document.querySelector("#payment-course");
const amount = document.querySelector("#payment-amount");
const referenceElement = document.querySelector("#payment-reference");

const params = new URLSearchParams(window.location.search);
const reference = params.get("external_reference") || "";
const paymentId = params.get("payment_id") || params.get("collection_id") || "";
const result = params.get("result") || "";

const POLL_INTERVAL_MS = 12000;
const MAX_POLL_ATTEMPTS = 30;

let pollAttempts = 0;
let pollTimerId = null;
let firstStatusRequest = true;
let moodleRedirectStarted = false;

const currencyFormatter = new Intl.NumberFormat("pt-BR", {
  style: "currency",
  currency: "BRL",
});

function setState(state, nextTitle, nextMessage, nextIcon, busy = false) {
  card.dataset.state = state;
  card.setAttribute("aria-busy", busy ? "true" : "false");
  title.textContent = nextTitle;
  message.textContent = nextMessage;
  icon.textContent = nextIcon;
}

function clearPolling() {
  if (pollTimerId !== null) {
    window.clearTimeout(pollTimerId);
    pollTimerId = null;
  }
}

function scheduleNextCheck() {
  if (pollAttempts >= MAX_POLL_ATTEMPTS) {
    clearPolling();
    setState(
      "success",
      "Pagamento recebido",
      "Seu pagamento foi confirmado, mas a liberação do curso está levando mais tempo que o esperado. Você pode acompanhar pela sua conta; não é necessário refazer a compra.",
      "✓",
    );
    return;
  }

  clearPolling();
  pollTimerId = window.setTimeout(loadOrderStatus, POLL_INTERVAL_MS);
}

async function openMoodleCourse(order) {
  if (moodleRedirectStarted || order?.access_status !== "active") {
    return;
  }

  const slug = order.course?.slug || "";

  if (!slug) {
    return;
  }

  moodleRedirectStarted = true;

  setState(
    "success",
    "Pagamento confirmado",
    "Seu curso está liberado. Estamos abrindo o ambiente de aprendizagem.",
    "✓",
    true,
  );

  try {
    const response = await fetch("./api/account/sso.php", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({ slug }),
    });

    const data = await response.json().catch(() => null);

    if (response.status === 401) {
      window.location.replace("./acesso.html");
      return;
    }

    if (!response.ok || typeof data?.url !== "string" || !data.url.startsWith("https://")) {
      throw new Error(data?.message || "Não foi possível abrir o Moodle.");
    }

    window.location.assign(data.url);
  } catch (error) {
    console.error("Falha ao abrir o Moodle:", error);
    moodleRedirectStarted = false;

    setState(
      "success",
      "Pagamento confirmado",
      "Seu curso está liberado. Não conseguimos abrir o ambiente automaticamente agora; use “Ir para minha conta” para acessar o curso.",
      "✓",
    );
  }
}

function renderOrder(order) {
  if (!order) return false;

  details.hidden = false;
  course.textContent = order.course?.title || "Curso EVA";

  const numericAmount = Number(order.amount);
  amount.textContent = Number.isFinite(numericAmount)
    ? currencyFormatter.format(numericAmount)
    : `${order.amount || "—"} ${order.currency || ""}`.trim();

  referenceElement.textContent = order.reference || reference || "—";

  switch (order.status) {
    case "paid":
      if (order.access_status === "active") {
        setState(
          "success",
          "Pagamento confirmado",
          "Seu acesso ao curso está disponível. Vamos abrir o ambiente de aprendizagem.",
          "✓",
          true,
        );
        clearPolling();
        openMoodleCourse(order);
        return false;
      }

      setState(
        "success",
        "Pagamento confirmado",
        "Recebemos seu pagamento. Estamos concluindo a liberação do curso e esta página será atualizada automaticamente.",
        "✓",
        true,
      );
      return true;

    case "pending":
    case "created":
      setState(
        "pending",
        "Pagamento em processamento",
        "O Mercado Pago ainda está concluindo a confirmação. Esta página será atualizada automaticamente; não é necessário gerar outro QR Code.",
        "…",
        true,
      );
      return true;

    case "refunded":
      clearPolling();
      setState(
        "failure",
        "Pagamento reembolsado",
        "O pagamento foi reembolsado. Se você acredita que isso ocorreu por engano, fale com a EVA.",
        "↩",
      );
      return false;

    case "cancelled":
    case "failed":
      clearPolling();
      setState(
        "failure",
        "Pagamento não concluído",
        "A compra não foi concluída. Você pode voltar aos cursos e tentar novamente.",
        "×",
      );
      return false;

    default:
      setState(
        result === "failure" ? "failure" : "pending",
        "Estamos verificando o pagamento",
        "Ainda não recebemos uma confirmação definitiva. Esta página continuará verificando o pedido automaticamente.",
        result === "failure" ? "×" : "…",
        result !== "failure",
      );
      return result !== "failure";
  }
}

async function loadOrderStatus() {
  clearPolling();

  if (!reference) {
    setState(
      result === "failure" ? "failure" : "pending",
      result === "failure" ? "Pagamento não concluído" : "Retorno recebido",
      "Não recebemos uma referência de pedido suficiente para consultar o pagamento automaticamente. Acompanhe o status pela sua conta.",
      result === "failure" ? "×" : "…",
    );
    return;
  }

  pollAttempts += 1;

  const controller = new AbortController();
  const timeoutId = window.setTimeout(() => controller.abort(), 12000);

  try {
    const response = await fetch("./api/orders/status.php", {
      method: "POST",
      credentials: "same-origin",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        reference,
        payment_id: firstStatusRequest && paymentId ? paymentId : undefined,
      }),
      signal: controller.signal,
    });

    firstStatusRequest = false;

    if (response.status === 401) {
      clearPolling();
      setState(
        "pending",
        "Entre na sua conta para acompanhar",
        "Seu retorno do Mercado Pago foi recebido, mas sua sessão EVA não está ativa. Faça login para consultar o pedido.",
        "…",
      );
      return;
    }

    const data = await response.json().catch(() => null);

    if (!response.ok || data?.success !== true) {
      throw new Error(data?.message || "Não foi possível consultar o pedido.");
    }

    if (renderOrder(data.order)) {
      scheduleNextCheck();
    }
  } catch (error) {
    console.error("Falha ao consultar pagamento:", error);

    if (pollAttempts < MAX_POLL_ATTEMPTS && result !== "failure") {
      setState(
        "pending",
        error?.name === "AbortError"
          ? "A confirmação está levando um pouco mais de tempo"
          : "Estamos aguardando a confirmação",
        "Não é necessário refazer a compra ou gerar outro QR Code. Vamos tentar novamente automaticamente.",
        "…",
        true,
      );
      scheduleNextCheck();
      return;
    }

    clearPolling();
    setState(
      result === "failure" ? "failure" : "pending",
      "Não foi possível atualizar o status agora",
      "O retorno do pagamento foi recebido. Você pode acompanhar o pedido pela sua conta sem refazer a compra.",
      result === "failure" ? "×" : "…",
    );
  } finally {
    window.clearTimeout(timeoutId);
  }
}

window.addEventListener("pagehide", clearPolling);
loadOrderStatus();
