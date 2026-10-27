// Сайт ТОО «MEDIA Информ Плюс»: контакты специалистов, окно «Связаться», форма записи,
// меню, вкладки и анимации. Без сборки — подключается как ES-модуль.
import { SITE } from './site.config.js';

const $ = (s, root = document) => root.querySelector(s);
const $$ = (s, root = document) => [...root.querySelectorAll(s)];
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const icon = (name, cls = '') => `<svg class="ic ${cls}" aria-hidden="true"><use href="#i-${name}"/></svg>`;
const DIRS = ['valuation', 'replan', 'deals'];
const DIR_ICON = { valuation: 'calc', replan: 'plan', deals: 'key' };

document.documentElement.classList.add('js');

// ---------- Телефоны ----------
// 10 цифр национального номера РК (без +7 / 8) или '' если номер не распознан.
function national(raw) {
  let d = String(raw || '').replace(/\D/g, '');
  if (d.length === 11 && (d[0] === '8' || d[0] === '7')) d = d.slice(1);
  return d.length === 10 ? d : '';
}
const telHref = raw => { const n = national(raw); return n ? '+7' + n : ''; };
function formatMobile(n) { return `+7 (${n.slice(0, 3)}) ${n.slice(3, 6)}-${n.slice(6, 8)}-${n.slice(8, 10)}`; }

// Контакты специалиста; телефон и почта без своих значений берутся у компании.
function contactsOf(dir) {
  const s = SITE.specialists[dir] || {};
  const c = SITE.company;
  const own = !!national(s.phone);
  return {
    ...s,
    dir,
    phone: own ? s.phone : c.phone,
    phoneHref: own ? telHref(s.phone) : c.phoneHref,
    ownPhone: own,
    email: s.email || c.email,
    wa: national(s.whatsapp),
    tg: String(s.telegram || '').replace(/^@/, '').trim(),
    ig: String(s.instagram || '').replace(/^@/, '').trim()
  };
}
const waUrl = (n, text) => `https://wa.me/7${n}${text ? '?text=' + encodeURIComponent(text) : ''}`;

function avatar(s) {
  if (s.photo) return `<span class="ava"><img src="img/${esc(s.photo)}" alt="" loading="lazy" width="46" height="46"></span>`;
  if (s.name) return `<span class="ava">${esc(s.name.split(/\s+/).map(w => w[0]).slice(0, 2).join('').toUpperCase())}</span>`;
  return `<span class="ava">${icon(DIR_ICON[s.dir])}</span>`;
}
function linksHtml(s, { compact = false } = {}) {
  const out = [];
  out.push(`<a class="chip-btn" href="tel:${esc(s.phoneHref)}">${icon('phone')}${compact ? 'Позвонить' : esc(s.phone)}</a>`);
  if (s.wa) out.push(`<a class="chip-btn wa" href="${waUrl(s.wa)}" target="_blank" rel="noopener">${icon('wa')}WhatsApp</a>`);
  if (s.tg) out.push(`<a class="chip-btn tg" href="https://t.me/${encodeURIComponent(s.tg)}" target="_blank" rel="noopener">${icon('tg')}Telegram</a>`);
  if (s.ig) out.push(`<a class="chip-btn ig" href="https://instagram.com/${encodeURIComponent(s.ig)}" target="_blank" rel="noopener">${icon('ig')}Instagram</a>`);
  if (s.email && !compact) out.push(`<a class="chip-btn" href="mailto:${esc(s.email)}">${icon('mail')}${esc(s.email)}</a>`);
  else if (s.email) out.push(`<a class="chip-btn" href="mailto:${esc(s.email)}" aria-label="Написать на ${esc(s.email)}">${icon('mail')}E-mail</a>`);
  return out.join('');
}
function whoHtml(s) {
  return `<div class="who">${avatar(s)}<div><b>${esc(s.name || s.role)}</b><span>${esc(s.name ? s.role : s.about)}</span></div></div>`;
}

// Блоки специалистов в карточках направлений
function renderSpecs() {
  $$('[data-spec]').forEach(box => {
    const s = contactsOf(box.dataset.spec);
    box.innerHTML = `${whoHtml(s)}<div class="links">${linksHtml(s, { compact: true })}</div>
      <button class="btn block" type="button" data-contact="${s.dir}">${icon('calendar')}Записаться на консультацию</button>`;
  });
  const list = $('[data-specs-list]');
  if (list) {
    list.innerHTML = DIRS.map(dir => {
      const s = contactsOf(dir);
      const link = s.ownPhone ? `<a href="tel:${esc(s.phoneHref)}">${esc(s.phone)}</a>`
        : s.wa ? `<a href="${waUrl(s.wa)}" target="_blank" rel="noopener">WhatsApp</a>`
        : `<a href="#" data-contact="${dir}">Записаться</a>`;
      return `<div class="row"><span>${esc(s.title)}${s.name ? ' — ' + esc(s.name) : ''}</span>${link}</div>`;
    }).join('');
  }
  $$('[data-co-phone]').forEach(a => { a.href = 'tel:' + SITE.company.phoneHref; if (!a.children.length) a.textContent = SITE.company.phone; });
  $$('[data-co-email]').forEach(a => { a.href = 'mailto:' + SITE.company.email; if (!a.children.length) a.textContent = SITE.company.email; });
  $$('[data-co-hours]').forEach(el => { if (SITE.company.hours) el.textContent = SITE.company.hours; else el.hidden = true; });
}

// ---------- Форма записи ----------
let uid = 0;
function formHtml(dir) {
  const id = 'f' + (++uid);
  return `<form class="form" novalidate data-lead>
    <div class="field"><span class="lbl" id="${id}-d">Направление</span>
      <div class="pick" role="radiogroup" aria-labelledby="${id}-d">${DIRS.map(d => `<label><input type="radio" name="dir" value="${d}" ${d === dir ? 'checked' : ''}>
        <span class="opt">${icon(DIR_ICON[d])}${esc(SITE.specialists[d].title)}</span></label>`).join('')}</div></div>
    <div class="spec-mini" data-mini></div>
    <div class="row2">
      <div class="field"><label for="${id}-n">Имя <i>*</i></label><input class="input" id="${id}-n" name="name" autocomplete="name" placeholder="Как к вам обращаться" maxlength="80"><span class="err">Укажите имя</span></div>
      <div class="field"><label for="${id}-p">Телефон <i>*</i></label><input class="input" id="${id}-p" name="phone" type="tel" inputmode="tel" autocomplete="tel" placeholder="+7 (___) ___-__-__"><span class="err">Нужен номер из 10 цифр после +7</span></div>
    </div>
    <div class="field"><label for="${id}-t">Когда удобно связаться</label>
      <select class="input" id="${id}-t" name="time"><option value="">В любое время</option><option>Утром</option><option>Днём</option><option>Вечером</option></select></div>
    <div class="field"><label for="${id}-c">Ваш вопрос</label><textarea class="input" id="${id}-c" name="comment" rows="3" maxlength="1500" placeholder="Например: нужна оценка квартиры для банка"></textarea></div>
    <input class="hp" type="text" name="website" tabindex="-1" autocomplete="off" aria-hidden="true">
    <button class="btn block" type="submit">${icon('send')}Оставить заявку</button>
    <p class="consent">Нажимая кнопку, вы соглашаетесь на обработку персональных данных в соответствии с <a href="privacy.html">политикой конфиденциальности</a>.</p>
  </form>`;
}

function phoneMask(input) {
  const fmt = () => {
    const raw = input.value;
    // «+7» маски не считаем цифрой номера; привычную восьмёрку в начале («8 701…») отбрасываем
    let d = (raw.startsWith('+7') ? raw.slice(2) : raw).replace(/\D/g, '');
    if (d.length === 11 && /^[78]/.test(d)) d = d.slice(1);
    else if (d[0] === '8') d = d.slice(1);
    d = d.slice(0, 10);
    let v = '+7';
    if (d.length) v += ' (' + d.slice(0, 3);
    if (d.length >= 3) v += ')';
    if (d.length > 3) v += ' ' + d.slice(3, 6);
    if (d.length > 6) v += '-' + d.slice(6, 8);
    if (d.length > 8) v += '-' + d.slice(8, 10);
    input.value = d.length ? v : '';
  };
  input.addEventListener('input', fmt);
  input.addEventListener('focus', () => { if (!input.value) input.value = '+7 '; });
  input.addEventListener('blur', () => { if (input.value.replace(/\D/g, '').length <= 1) input.value = ''; });
}

function mountForm(root) {
  const form = $('[data-lead]', root);
  const mini = $('[data-mini]', form);
  const dirOf = () => form.elements.dir.value || 'valuation';
  const paintMini = () => {
    const s = contactsOf(dirOf());
    mini.innerHTML = `${whoHtml(s)}<div class="links">${linksHtml(s, { compact: true })}</div>`;
  };
  paintMini();
  form.addEventListener('change', e => { if (e.target.name === 'dir') paintMini(); });
  phoneMask(form.elements.phone);
  $$('.input', form).forEach(i => i.addEventListener('input', () => i.closest('.field')?.classList.remove('bad')));

  form.addEventListener('submit', async e => {
    e.preventDefault();
    const f = form.elements;
    const name = f.name.value.trim();
    const phone = national(f.phone.value);
    let ok = true;
    if (name.length < 2) { f.name.closest('.field').classList.add('bad'); ok = false; }
    if (!phone) { f.phone.closest('.field').classList.add('bad'); ok = false; }
    if (!ok) { form.querySelector('.bad .input').focus(); return; }
    if (f.website.value) return; // бот заполнил скрытое поле

    const lead = {
      dir: dirOf(), title: SITE.specialists[dirOf()].title, name,
      phone: formatMobile(phone), time: f.time.value, comment: f.comment.value.trim(),
      page: location.href
    };
    const btn = form.querySelector('[type=submit]');
    btn.disabled = true;
    btn.innerHTML = `${icon('clock')}Отправляем…`;
    const sent = await sendLead(lead);
    root.replaceChildren(doneEl(lead, sent));
  });
}

async function sendLead(lead) {
  if (!SITE.leadEndpoint) return false;
  const ctrl = new AbortController();
  const t = setTimeout(() => ctrl.abort(), 9000);
  try {
    const res = await fetch(SITE.leadEndpoint, {
      method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify(lead), signal: ctrl.signal
    });
    const data = await res.json().catch(() => ({}));
    return res.ok && data.ok === true;
  } catch { return false; } finally { clearTimeout(t); }
}

function leadText(l) {
  return [`Здравствуйте! Заявка с сайта на консультацию.`, `Направление: ${l.title}`, `Имя: ${l.name}`, `Телефон: ${l.phone}`,
    l.time && `Удобное время: ${l.time}`, l.comment && `Вопрос: ${l.comment}`].filter(Boolean).join('\n');
}

// Экран после отправки. Если заявка не ушла автоматически — даём отправить её самому.
function doneEl(lead, sent) {
  const s = contactsOf(lead.dir);
  const text = leadText(lead);
  const el = document.createElement('div');
  el.className = 'done';
  el.setAttribute('role', 'status');
  if (sent) {
    el.innerHTML = `<span class="mark">${icon('check')}</span><h3>Заявка отправлена</h3>
      <p>Спасибо, ${esc(lead.name)}! ${esc(s.name || s.role)} свяжется с вами по номеру ${esc(lead.phone)}${lead.time ? ' — ' + esc(lead.time.toLowerCase()) : ''}.</p>
      <div class="links">${linksHtml(s, { compact: true })}</div>`;
  } else {
    const mail = `mailto:${s.email}?subject=${encodeURIComponent('Заявка с сайта: ' + lead.title)}&body=${encodeURIComponent(text)}`;
    const btns = [];
    if (s.wa) btns.push(`<a class="btn" href="${waUrl(s.wa, text)}" target="_blank" rel="noopener">${icon('wa')}Отправить в WhatsApp</a>`);
    if (s.tg) btns.push(`<a class="btn ${s.wa ? 'ghost' : ''}" href="https://t.me/${encodeURIComponent(s.tg)}" target="_blank" rel="noopener" data-copy>${icon('tg')}Написать в Telegram</a>`);
    btns.push(`<a class="btn ${s.wa || s.tg ? 'ghost' : ''}" href="${mail}">${icon('mail')}Отправить на почту</a>`);
    btns.push(`<a class="btn ghost" href="tel:${esc(s.phoneHref)}">${icon('phone')}Позвонить: ${esc(s.phone)}</a>`);
    el.innerHTML = `<span class="mark wait">${icon('send')}</span><h3>Осталось отправить заявку</h3>
      <p>Текст заявки готов — выберите, куда его отправить. ${esc(s.name || s.role)} ответит в рабочее время.</p>
      <div class="links" style="flex-direction:column;align-items:stretch;width:100%;max-width:340px">${btns.join('')}</div>`;
    $$('[data-copy]', el).forEach(a => a.addEventListener('click', () => copy(text, 'Текст заявки скопирован — вставьте его в чат')));
  }
  return el;
}

async function copy(text, msg) {
  try { await navigator.clipboard.writeText(text); toast(msg); } catch { /* буфер обмена недоступен */ }
}
let toastTimer;
function toast(msg) {
  let t = $('.toast');
  if (!t) { t = document.createElement('div'); t.className = 'toast'; t.setAttribute('role', 'status'); document.body.append(t); }
  t.textContent = msg;
  t.classList.add('show');
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => t.classList.remove('show'), 3200);
}

// ---------- Окно «Связаться» ----------
const modal = $('#contact-modal');
let opener = null;
function openModal(dir) {
  opener = document.activeElement;
  const body = $('[data-modal-body]', modal);
  body.innerHTML = formHtml(DIRS.includes(dir) ? dir : 'valuation');
  mountForm(body);
  modal.hidden = false;
  void modal.offsetWidth; // чтобы сработала анимация появления
  modal.classList.add('open');
  document.documentElement.style.overflow = 'hidden';
  $$('body > :not(#contact-modal):not(script)').forEach(el => el.inert = true);
  setTimeout(() => $('input[name=name]', modal)?.focus({ preventScroll: true }), 60);
}
function closeModal() {
  if (!modal.classList.contains('open')) return;
  modal.classList.remove('open');
  document.documentElement.style.overflow = '';
  $$('body > [inert]').forEach(el => el.inert = false);
  setTimeout(() => { if (!modal.classList.contains('open')) modal.hidden = true; }, 350);
  opener?.focus?.({ preventScroll: true });
}
document.addEventListener('click', e => {
  const b = e.target.closest('[data-contact]');
  if (b) { e.preventDefault(); closeMenu(); openModal(b.dataset.contact); return; }
  if (e.target.closest('[data-close]')) closeModal();
});
document.addEventListener('keydown', e => {
  if (e.key === 'Escape') { closeModal(); closeMenu(); }
  if (e.key === 'Tab' && modal.classList.contains('open')) {
    const f = $$('a[href], button:not([disabled]), input:not(.hp), select, textarea', modal).filter(el => el.offsetParent);
    if (!f.length) return;
    if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f.at(-1).focus(); }
    else if (!e.shiftKey && document.activeElement === f.at(-1)) { e.preventDefault(); f[0].focus(); }
  }
});

// ---------- Меню ----------
const mnav = $('#mnav');
function closeMenu() {
  if (!mnav?.classList.contains('open')) return;
  mnav.classList.remove('open');
  document.documentElement.style.overflow = '';
  $('[data-menu]')?.setAttribute('aria-expanded', 'false');
}
$('[data-menu]')?.addEventListener('click', e => {
  mnav.classList.add('open');
  document.documentElement.style.overflow = 'hidden';
  e.currentTarget.setAttribute('aria-expanded', 'true');
  $('a', mnav)?.focus({ preventScroll: true });
});
mnav?.addEventListener('click', e => { if (e.target.closest('a[href^="#"], [data-menu-close]')) closeMenu(); });

// Шапка с тенью и подсветка текущего раздела
const header = $('.header');
const mbar = $('.mbar');
const hero = $('.hero');
const onScroll = () => {
  header.classList.toggle('scrolled', scrollY > 8);
  if (mbar && hero) mbar.classList.toggle('show', hero.getBoundingClientRect().bottom < 80);
};
addEventListener('scroll', onScroll, { passive: true });
onScroll();

const navLinks = $$('.nav a[href^="#"]');
const sections = navLinks.map(a => document.getElementById(a.getAttribute('href').slice(1))).filter(Boolean);
if ('IntersectionObserver' in window && sections.length) {
  const seen = new Map();
  const io = new IntersectionObserver(entries => {
    entries.forEach(en => seen.set(en.target.id, en.isIntersecting));
    const cur = sections.find(s => seen.get(s.id));
    navLinks.forEach(a => a.setAttribute('aria-current', String(!!cur && a.getAttribute('href') === '#' + cur.id)));
  }, { rootMargin: '-45% 0px -50% 0px' });
  sections.forEach(s => io.observe(s));
}

// ---------- Вкладки документов ----------
$$('[role=tablist]').forEach(list => {
  const tabs = $$('[role=tab]', list);
  const select = tab => tabs.forEach(t => {
    const on = t === tab;
    t.setAttribute('aria-selected', String(on));
    t.tabIndex = on ? 0 : -1;
    document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
  });
  list.addEventListener('click', e => { const t = e.target.closest('[role=tab]'); if (t) select(t); });
  list.addEventListener('keydown', e => {
    const i = tabs.indexOf(document.activeElement);
    if (i < 0 || !['ArrowRight', 'ArrowLeft'].includes(e.key)) return;
    const n = tabs[(i + (e.key === 'ArrowRight' ? 1 : tabs.length - 1)) % tabs.length];
    select(n); n.focus();
  });
});

// ---------- Появление при прокрутке ----------
const revealEls = $$('.reveal, .reveal-group');
if ('IntersectionObserver' in window && !matchMedia('(prefers-reduced-motion: reduce)').matches) {
  const io = new IntersectionObserver(entries => entries.forEach(en => {
    if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
  }), { rootMargin: '0px 0px -8% 0px', threshold: .08 });
  revealEls.forEach(el => io.observe(el));
} else revealEls.forEach(el => el.classList.add('in'));

// ---------- Старт ----------
renderSpecs();
$$('[data-inline-form]').forEach(box => { box.innerHTML = formHtml(box.dataset.inlineForm || 'valuation'); mountForm(box); });
$$('[data-year]').forEach(el => { el.textContent = new Date().getFullYear(); });
if (location.hash === '#contact') openModal();
