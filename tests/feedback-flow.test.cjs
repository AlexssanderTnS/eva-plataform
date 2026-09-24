'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '..');
const read = (name) => fs.readFileSync(path.join(root, name), 'utf8');

test('e-mail da compra direciona ao curso adquirido na conta', () => {
  const email = read('jobs/purchase-email.php');
  assert.match(email, /c\.slug AS course_slug/);
  assert.match(email, /\/conta\.html\?curso=/);
  assert.match(email, /#meus-cursos/);
  assert.doesNotMatch(email, /\. '\/cursos';/);
});

test('redirecionamento após login usa destino permitido e preserva a compra', () => {
  const auth = read('js/auth.js');
  assert.match(auth, /loginParams\.get\('next'\) === 'meus-cursos'/);
  assert.match(auth, /const pendingCourse = sessionStorage\.getItem\('eva_pending_course'\)/);
  assert.match(auth, /accountLoginUrl\(\)/);
  assert.match(auth, /\^\[a-z0-9\]/);
});

test('área de cursos destaca a compra e exige sessão EVA', () => {
  const courses = read('js/account-courses.js');
  assert.match(courses, /item\.classList\.add\('is-target'\)/);
  assert.match(courses, /response\.status === 401 \|\| response\.status === 403/);
  assert.match(courses, /next=meus-cursos/);
});

test('pagamento não chama pendente de confirmado e ainda abre Moodle quando ativo', () => {
  const payment = read('js/pagamento.js');
  assert.match(payment, /lastKnownOrderStatus === "paid"/);
  assert.match(payment, /Ainda não temos a confirmação do pagamento/);
  assert.match(payment, /case "pending":/);
  assert.match(payment, /openMoodleCourse\(order\)/);
  assert.match(payment, /window\.location\.assign\(data\.url\)/);
});

test('pagamento oferece atalho para o curso adquirido', () => {
  const paymentHtml = read('pagamento.html');
  const paymentJs = read('js/pagamento.js');
  assert.match(paymentHtml, /id="payment-account-link"/);
  assert.match(paymentJs, /accountLink\.href = "\.\/conta\.html\?curso="/);
});

test('links da Home e Contato usam destinos pedidos', () => {
  assert.match(read('index.html'), /data-scroll-block="center"/);
  assert.match(read('index.html'), /href="#about" class="btn btn-secondary"/);
  assert.match(read('js/main.js'), /this\.dataset\.scrollBlock === "center"/);
  assert.match(read('contato.html'), /https:\/\/wa\.me\/5521973410015/);
  assert.match(read('contato.html'), /href="#formulario-contato"/);
});

test('páginas carregam versões atualizadas dos scripts', () => {
  assert.match(read('conta.html'), /auth\.js\?v=20260924-purchase-link/);
  assert.match(read('conta.html'), /account-courses\.js\?v=20260924-purchase-link/);
  assert.match(read('pagamento.html'), /pagamento\.js\?v=20260924-paymentflow2/);
});

test('arquivos JavaScript alterados possuem sintaxe válida', () => {
  for (const name of ['js/auth.js', 'js/account-courses.js', 'js/pagamento.js']) {
    assert.doesNotThrow(() => new Function(read(name)), name);
  }
});
