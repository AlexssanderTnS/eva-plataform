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

const currencyFormatter = new Intl.NumberFormat("pt-BR", {
  style: "currency",
  currency: "BRL",
});

function setState(state, nextTitle, nextMessage, nextIcon) {
  card.dataset.state = state;
  card.setAttribute("aria-busy", "false");
  title.textContent = nextTitle;
  message.textContent = nextMessage;
  icon.textContent = nextIcon;
}

function renderOrder(order) {
  if (!order) return;

  details.hidden = false;
  course.textContent = order.course?.title || "Curso EVA";

  const numericAmount = Number(order.amount);
  amount.textContent = Number.isFinite(numericAmount)
    ? currencyFormatter.format(numericAmount)
    : `${order.amount || "—"} ${order.currency || ""}`.trim();

  referenceElement.textContent = order.reference || reference || "—";

  switch (order.status) {
    case "paid":
      setState(
        "success",
        "Pagamento confirmado",
        order.access_status === "active"
          ? "Seu acesso ao curso já está disponível na sua conta."
          : "Recebemos seu pagamento. Seu acesso ao curso está sendo preparado e aparecerá na sua conta assim que a liberação for concluída.",
        "✓",
      );
      break;
    case "pending":
    case "created":
      setState(
        "pending",
        "Pagamento em processamento",
        "O Mercado Pago ainda não confirmou a conclusão do pagamento. Você pode acompanhar a atualização pela sua conta.",
        "…",
      );
      break;
    case "refunded":
      setState(
        "failure",
        "Pagamento reembolsado",
        "O pagamento foi reembolsado. Se você acredita que isso ocorreu por engano, fale com a EVA.",
        "↩",
      );
      break;
    case "cancelled":
    case "failed":
      setState(
        "failure",
        "Pagamento não concluído",
        "A compra não foi concluída. Você pode voltar aos cursos e tentar novamente.",
        "×",
      );
      break;
    default:
      setState(
        result === "failure" ? "failure" : "pending",
        "Estamos verificando o pagamento",
        "Ainda não recebemos uma confirmação definitiva. Consulte novamente pela sua conta em alguns instantes.",
        "…",
      );
  }
}

async function loadOrderStatus() {
  if (!reference) {
    setState(
      result === "failure" ? "failure" : "pending",
      result === "failure" ? "Pagamento não concluído" : "Retorno recebido",
      "Não recebemos uma referência de pedido suficiente para consultar o pagamento automaticamente. Acompanhe o status pela sua conta.",
      result === "failure" ? "×" : "…",
    );
    return;
  }

  try {
    const response = await fetch("./api/orders/status.php", {
      method: "POST",
      headers: {
        Accept: "application/json",
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        reference,
        payment_id: paymentId || undefined,
      }),
    });

    if (response.status === 401) {
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

    renderOrder(data.order);
  } catch (error) {
    console.error("Falha ao consultar pagamento:", error);
    setState(
      result === "failure" ? "failure" : "pending",
      "Não foi possível atualizar o status agora",
      "O retorno do pagamento foi recebido, mas a consulta automática falhou. O status continuará sendo atualizado pelo sistema e poderá ser conferido na sua conta.",
      result === "failure" ? "×" : "…",
    );
  }
}

loadOrderStatus();
