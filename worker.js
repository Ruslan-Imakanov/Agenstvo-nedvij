// Сайт ТОО «MEDIA Информ Плюс». Отдаёт статические файлы из public/ (корень и короткие адреса — без редиректов)
// и принимает заявки из формы: POST /api/lead → сообщение в Telegram.
// Выкладывается как Cloudflare Worker «mip-site» (html_handling: "none", not_found_handling: "404-page").
//
// Переменные (Cloudflare → Workers → mip-site → Settings → Variables and Secrets):
//   TG_TOKEN — токен Telegram-бота (секрет), TG_CHAT — чат для всех заявок;
//   TG_CHAT_VALUATION / TG_CHAT_REPLAN / TG_CHAT_DEALS — свой чат для специалиста направления (необязательно).
// Пока TG_TOKEN и TG_CHAT не заданы, /api/lead отвечает { ok: false } и сайт предлагает отправить заявку в WhatsApp или на почту.
// compatibility_flags: ["assets_navigation_has_no_effect"] — обязательно: без него Cloudflare отвечает на переходы
// браузера («/», «/privacy») сам, не вызывая воркер, и показывает 404.html.
const DIRS = { valuation: 'Оценка', replan: 'Перепланировка', deals: 'Купля-продажа' };

export default {
  async fetch(request, env) {
    const url = new URL(request.url);
    if (url.pathname === '/api/lead') return lead(request, env, url);
    let path = url.pathname;
    if (path === '/' || path === '') path = '/index.html';
    else if (!path.split('/').pop().includes('.')) path = path.replace(/\/$/, '') + '.html';
    if (path !== url.pathname) {
      const res = await env.ASSETS.fetch(new Request(new URL(path + url.search, url), request));
      if (res.status !== 404) return res;
    }
    return env.ASSETS.fetch(request);
  }
};

const json = (data, status = 200) => new Response(JSON.stringify(data), {
  status, headers: { 'content-type': 'application/json; charset=utf-8', 'cache-control': 'no-store' }
});

async function lead(request, env, url) {
  if (request.method !== 'POST') return json({ ok: false, error: 'method' }, 405);
  const origin = request.headers.get('origin');
  if (origin && new URL(origin).host !== url.host) return json({ ok: false, error: 'origin' }, 403);
  const body = await request.json().catch(() => null);
  if (!body || typeof body !== 'object') return json({ ok: false, error: 'bad_json' }, 400);

  const clip = (v, n) => String(v ?? '').trim().slice(0, n);
  const name = clip(body.name, 80);
  const phone = clip(body.phone, 40);
  const key = Object.hasOwn(DIRS, body.dir) ? body.dir : 'valuation';
  if (name.length < 2 || phone.replace(/\D/g, '').length < 10) return json({ ok: false, error: 'invalid' }, 400);

  const chat = env['TG_CHAT_' + key.toUpperCase()] || env.TG_CHAT;
  // 200, а не 503: это штатный режим «отправьте сами», а не сбой — браузер не пишет ошибку в консоль
  if (!env.TG_TOKEN || !chat) return json({ ok: false, error: 'not_configured' });

  const text = [
    `Заявка с сайта — ${DIRS[key]}`,
    `Имя: ${name}`,
    `Телефон: ${phone}`,
    body.time && `Удобное время: ${clip(body.time, 40)}`,
    body.comment && `Вопрос: ${clip(body.comment, 1500)}`
  ].filter(Boolean).join('\n');
  const res = await fetch(`https://api.telegram.org/bot${env.TG_TOKEN}/sendMessage`, {
    method: 'POST',
    headers: { 'content-type': 'application/json' },
    body: JSON.stringify({ chat_id: chat, text, disable_web_page_preview: true })
  }).catch(() => null);
  return res?.ok ? json({ ok: true }) : json({ ok: false, error: 'telegram' }, 502);
}
